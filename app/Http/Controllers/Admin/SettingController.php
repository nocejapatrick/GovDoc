<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        return Inertia::render('admin/Settings', [
            'settings' => [
                'ai_module_enabled' => Setting::flag('ai_module_enabled'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        $data = $request->validate([
            'ai_module_enabled' => ['required', 'boolean'],
        ]);

        Setting::set('ai_module_enabled', $data['ai_module_enabled'] ? '1' : '0');

        return back();
    }
}
