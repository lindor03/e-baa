<?php

namespace Webkul\CustomSettings\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\CustomSettings\Models\CustomColor;

class CustomSettingsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $colors = CustomColor::all();

        return view('customsettings::admin.index', compact('colors'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('customsettings::admin.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'keys'   => 'required|array',
            'values' => 'required|array',
        ]);

        foreach ($validated['keys'] as $index => $key) {
            if (!empty($key) && isset($validated['values'][$index])) {
                CustomColor::updateOrCreate(
                    ['key' => $key],
                    ['value' => $validated['values'][$index]]
                );
            }
        }

        session()->flash('success', 'Custom colors saved.');

        return redirect()->route('admin.customsettings.index');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id)
    {
        $color = CustomColor::findOrFail($id);

        return view('customsettings::admin.edit', compact('color'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $id)
    {
        $color = CustomColor::findOrFail($id);

        $request->validate([
            'key'   => 'required|string',
            'value' => 'required|string',
        ]);

        $color->key   = $request->input('key');
        $color->value = $request->input('value');
        $color->save();

        session()->flash('success', 'Color updated.');

        return redirect()->route('admin.customsettings.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id)
    {
        $color = CustomColor::findOrFail($id);
        $color->delete();

        session()->flash('success', 'Color deleted.');

        return redirect()->route('admin.customsettings.index');
    }
}
