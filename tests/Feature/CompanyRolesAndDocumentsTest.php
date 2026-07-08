<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use App\Services\ChartOfAccountsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyRolesAndDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $alpha;

    protected Company $beta;

    protected User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $chart = app(ChartOfAccountsService::class);
        $this->alpha = Company::create(['name' => 'Alpha', 'code' => 'A', 'type' => 'holding']);
        $this->beta = Company::create(['name' => 'Beta', 'code' => 'B', 'type' => 'subsidiary', 'parent_id' => $this->alpha->id]);
        $chart->seedDefaultChart($this->alpha);
        $chart->seedDefaultChart($this->beta);

        // Accountant grade in Alpha only, but attached (viewer of nothing) to Beta too.
        $this->accountant = User::factory()->create(['current_company_id' => $this->alpha->id]);
        setPermissionsTeamId($this->alpha->id);
        $this->accountant->assignRole('accountant');
        $this->accountant->companies()->attach([$this->alpha->id, $this->beta->id]);
    }

    protected function expensePayload(Company $company, array $overrides = []): array
    {
        $chart = app(ChartOfAccountsService::class);

        return array_merge([
            'date' => '2026-03-01',
            'account_id' => $chart->find($company, '5000')->id,
            'paid_from_account_id' => $chart->findBySubtype($company, 'bank')->id,
            'amount' => 100,
            'document' => UploadedFile::fake()->create('receipt.pdf', 50, 'application/pdf'),
        ], $overrides);
    }

    public function test_role_only_applies_in_its_own_company(): void
    {
        // In Alpha the accountant can open the expenses screen...
        $this->actingAs($this->accountant)->get('/expenses')->assertOk();

        // ...but after switching to Beta, where they hold no role, they cannot.
        $this->accountant->forceFill(['current_company_id' => $this->beta->id])->save();

        $this->actingAs($this->accountant)->get('/expenses')->assertForbidden();
    }

    public function test_transactions_require_a_supporting_document(): void
    {
        $this->actingAs($this->accountant)
            ->post('/expenses', $this->expensePayload($this->alpha, ['document' => null]))
            ->assertSessionHasErrors('document');

        $this->assertSame(0, Expense::count());
    }

    public function test_transaction_stores_the_supporting_document(): void
    {
        Storage::fake('local');

        $this->actingAs($this->accountant)
            ->post('/expenses', $this->expensePayload($this->alpha))
            ->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();
        $attachment = $expense->attachments()->firstOrFail();

        $this->assertSame('receipt.pdf', $attachment->original_name);
        $this->assertSame($this->alpha->id, $attachment->company_id);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_attachments_are_only_downloadable_by_users_of_that_company(): void
    {
        Storage::fake('local');

        $this->actingAs($this->accountant)
            ->post('/expenses', $this->expensePayload($this->alpha))
            ->assertSessionHasNoErrors();

        $attachment = Attachment::firstOrFail();

        $this->actingAs($this->accountant)
            ->get('/attachments/'.$attachment->id)
            ->assertOk();

        // A user of an unrelated company cannot fetch it.
        $outsider = User::factory()->create();
        $gamma = Company::create(['name' => 'Gamma', 'code' => 'G', 'type' => 'holding']);
        app(ChartOfAccountsService::class)->seedDefaultChart($gamma);
        $outsider->forceFill(['current_company_id' => $gamma->id])->save();
        setPermissionsTeamId($gamma->id);
        $outsider->assignRole('holding-admin');
        $outsider->companies()->attach($gamma->id);

        $this->actingAs($outsider)
            ->get('/attachments/'.$attachment->id)
            ->assertForbidden();
    }
}
