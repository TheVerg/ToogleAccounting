<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    protected function logoDataUri(): ?string
    {
        foreach (['logo.png' => 'image/png', 'logo.jpg' => 'image/jpeg', 'logo.jpeg' => 'image/jpeg', 'logo.svg' => 'image/svg+xml'] as $file => $mime) {
            $path = public_path($file);
            if (is_file($path)) {
                return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path));
            }
        }

        return null;
    }

    protected function reportingCompanyIds(Request $request): array
    {
        $company = $request->user()->currentCompany;
        abort_unless($company, 403);

        $ids = collect([$company->id]);

        if ($company->isHolding()) {
            $ids = $ids->merge($company->subsidiaries()->pluck('id'));
        }

        return $ids->unique()->values()->all();
    }

    protected function canViewCompany(Request $request, int $companyId): bool
    {
        return in_array($companyId, $this->reportingCompanyIds($request), true);
    }
}
