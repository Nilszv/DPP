<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use Illuminate\Http\Request;

/** Admin editing of legal documents (registration policy, terms). Versioned on body change. */
class AdminLegalController extends Controller
{
    public function index()
    {
        return view('admin.legal.index', ['documents' => LegalDocument::orderBy('title')->get()]);
    }

    public function edit(LegalDocument $document)
    {
        return view('admin.legal.edit', compact('document'));
    }

    public function update(Request $request, LegalDocument $document)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);

        // Bump the version when the text changes so acceptance records stay meaningful.
        $version = $document->version + ($data['body'] !== $document->body ? 1 : 0);

        $document->update([
            'title' => $data['title'],
            'body' => $data['body'],
            'requires_acceptance' => $request->boolean('requires_acceptance'),
            'version' => $version,
        ]);

        return redirect()->route('admin.legal.index')->with('status', "Updated {$document->title}.");
    }
}
