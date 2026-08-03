<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\RecurringExpense;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Contacts/Index', [
            'contacts' => Contact::where('company_id', $request->user()->current_company_id)
                ->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        Contact::create($data + ['company_id' => $request->user()->current_company_id]);

        return back()->with('success', 'Contact created.');
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        abort_unless($contact->company_id === $request->user()->current_company_id, 403);

        $contact->update($this->validated($request));

        return back()->with('success', 'Contact updated.');
    }

    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        abort_unless($contact->company_id === $request->user()->current_company_id, 403);

        $inUse = $contact->invoices()->exists()
            || Expense::where('contact_id', $contact->id)->exists()
            || Payment::where('contact_id', $contact->id)->exists()
            || RecurringExpense::where('contact_id', $contact->id)->exists();

        if ($inUse) {
            return back()->with('error', $contact->name.' has transactions. Deactivate the contact instead of deleting it.');
        }

        $contact->delete();

        return back()->with('success', 'Contact deleted.');
    }

    /** CSV template for bulk client/vendor import. */
    public function importTemplate(): \Symfony\Component\HttpFoundation\Response
    {
        $csv = "Type,Name,Contact Person,Email,Phone,Tax Number,Address,Billing Address\n"
            ."customer,Example Client Ltd,Tariro Moyo,client@example.com,+263 771 000 000,VAT123,1 Main Street,Accounts Office 1 Main Street\n"
            ."vendor,Example Supplier (Pvt) Ltd,,supplier@example.com,,,,\n"
            ."both,Example Client & Supplier,,,,,,\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="contacts-template.csv"',
        ]);
    }

    /** Bulk import clients/vendors from a filled template. */
    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $companyId = $request->user()->current_company_id;
        $rows = array_values(array_filter(array_map('str_getcsv',
            preg_split('/\r\n|\r|\n/', trim($request->file('file')->get())))));
        array_shift($rows); // header

        $created = 0;
        $skipped = [];
        foreach ($rows as $i => $row) {
            $type = strtolower(trim($row[0] ?? ''));
            $name = trim($row[1] ?? '');
            if (! in_array($type, ['customer', 'vendor', 'both'], true) || $name === '') {
                $skipped[] = $i + 2;

                continue;
            }
            if (Contact::where('company_id', $companyId)->where('name', $name)->exists()) {
                $skipped[] = $i + 2;

                continue;
            }
            Contact::create([
                'company_id' => $companyId,
                'type' => $type,
                'name' => $name,
                'contact_person' => trim($row[2] ?? '') ?: null,
                'email' => trim($row[3] ?? '') ?: null,
                'phone' => trim($row[4] ?? '') ?: null,
                'tax_number' => trim($row[5] ?? '') ?: null,
                'address' => trim($row[6] ?? '') ?: null,
                'billing_address' => trim($row[7] ?? '') ?: null,
                'is_active' => true,
            ]);
            $created++;
        }

        $message = $created.' contact(s) imported.';
        if ($skipped !== []) {
            $message .= ' Skipped row(s) '.implode(', ', $skipped).' (invalid or duplicate).';
        }

        return back()->with($created ? 'success' : 'error', $message);
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(['customer', 'vendor', 'both'])],
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'tax_number' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'digits:10'],
            'vat_number' => ['nullable', 'digits:9'],
            'trade_name' => ['nullable', 'string', 'max:250'],
            'address' => ['nullable', 'string'],
            'billing_address' => ['nullable', 'string'],
            'province' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'street' => ['nullable', 'string', 'max:100'],
            'house_no' => ['nullable', 'string', 'max:250'],
            'district' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ]);
    }
}
