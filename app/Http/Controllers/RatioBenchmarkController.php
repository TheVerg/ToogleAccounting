<?php

namespace App\Http\Controllers;

use App\Models\CustomRatio;
use App\Models\RatioSetting;
use App\Services\ReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master Data > Ratio Benchmarks: per-company target ranges used by the
 * Financial Ratios statement. Built-in defaults apply until overridden.
 */
class RatioBenchmarkController extends Controller
{
    public function index(Request $request, ReportService $reports): Response
    {
        $company = $request->user()->currentCompany;
        $targets = $reports->ratioTargets($company);

        $sections = collect(ReportService::RATIO_DEFINITIONS)->map(fn ($section) => [
            'title' => $section['title'],
            'ratios' => collect($section['ratios'])->map(fn ($def) => [
                'key' => $def['key'],
                'label' => $def['label'],
                'formula' => $def['formula'],
                'format' => $def['format'],
                'default_min' => $def['min'],
                'default_max' => $def['max'],
                'target_min' => $targets[$def['key']]['min'],
                'target_max' => $targets[$def['key']]['max'],
                'overridden' => $targets[$def['key']]['overridden'],
            ])->values(),
        ])->values();

        return Inertia::render('Settings/RatioBenchmarks', [
            'sections' => $sections,
            'figures' => collect(ReportService::RATIO_FIGURES)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'sectionOptions' => CustomRatio::SECTIONS,
            'customRatios' => CustomRatio::where('company_id', $company->id)
                ->orderBy('section')->orderBy('label')->get(),
            'canManage' => $request->user()->can('companies.manage'),
        ]);
    }

    public function storeCustom(Request $request): RedirectResponse
    {
        $company = $request->user()->currentCompany;
        $figureKeys = array_keys(ReportService::RATIO_FIGURES);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'section' => ['required', Rule::in(CustomRatio::SECTIONS)],
            'numerator' => ['required', Rule::in($figureKeys)],
            'denominator' => ['required', Rule::in($figureKeys), 'different:numerator'],
            'format' => ['required', Rule::in(['x', '%'])],
            'target_min' => ['nullable', 'numeric'],
            'target_max' => ['nullable', 'numeric'],
        ]);

        if ($data['target_min'] !== null && $data['target_max'] !== null
            && (float) $data['target_max'] < (float) $data['target_min']) {
            return back()->withErrors(['target_max' => 'The maximum target must not be below the minimum.']);
        }

        CustomRatio::create($data + ['company_id' => $company->id]);

        return back()->with('success', 'Custom ratio "'.$data['label'].'" added.');
    }

    public function destroyCustom(Request $request, CustomRatio $customRatio): RedirectResponse
    {
        abort_unless($customRatio->company_id === $request->user()->current_company_id, 403);

        $customRatio->delete();

        return back()->with('success', 'Custom ratio removed.');
    }

    public function update(Request $request): RedirectResponse
    {
        $company = $request->user()->currentCompany;

        $validKeys = collect(ReportService::RATIO_DEFINITIONS)
            ->flatMap(fn ($s) => collect($s['ratios'])->pluck('key'))->all();

        $data = $request->validate([
            'ratios' => ['required', 'array'],
            'ratios.*.key' => ['required', 'string', Rule::in($validKeys)],
            'ratios.*.target_min' => ['nullable', 'numeric'],
            'ratios.*.target_max' => ['nullable', 'numeric'],
        ]);

        foreach ($data['ratios'] as $row) {
            if ($row['target_min'] !== null && $row['target_max'] !== null
                && (float) $row['target_max'] < (float) $row['target_min']) {
                return back()->withErrors([
                    'ratios' => 'The maximum target for "'.$row['key'].'" must not be below its minimum.',
                ]);
            }
        }

        foreach ($data['ratios'] as $row) {
            RatioSetting::updateOrCreate(
                ['company_id' => $company->id, 'key' => $row['key']],
                ['target_min' => $row['target_min'], 'target_max' => $row['target_max']],
            );
        }

        return back()->with('success', 'Ratio benchmarks updated for '.$company->name.'.');
    }

    /** Drop all overrides so the built-in defaults apply again. */
    public function reset(Request $request): RedirectResponse
    {
        $company = $request->user()->currentCompany;
        RatioSetting::where('company_id', $company->id)->get()->each->delete();

        return back()->with('success', 'Ratio benchmarks reset to defaults.');
    }
}
