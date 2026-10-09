<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProtocolVersion;
use App\Models\ResourceArticle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ResourceArticleController extends Controller
{
    public function index(ProtocolVersion $protocol): Response
    {
        return Inertia::render('Admin/Resources/Index', [
            'protocol' => [...$protocol->only(['id', 'code', 'name']), 'is_frozen' => $protocol->isFrozen()],
            'sections' => ResourceArticle::SECTIONS,
            'articles' => $protocol->resources()->orderBy('sort_order')->orderBy('id')->get(['id', 'section', 'title', 'sort_order', 'updated_at']),
        ]);
    }

    public function create(ProtocolVersion $protocol): Response
    {
        return Inertia::render('Admin/Resources/Form', [
            'protocol' => $protocol->only(['id', 'code', 'name']),
            'article' => null,
            'sections' => ResourceArticle::SECTIONS,
        ]);
    }

    public function store(Request $request, ProtocolVersion $protocol): RedirectResponse
    {
        abort_if($protocol->isFrozen(), 403, 'Dondurulmuş protokol değiştirilemez.');

        $protocol->resources()->create($this->validated($request));

        return redirect()->route('admin.protocols.resources.index', $protocol)->with('success', 'Kaynak sayfası eklendi.');
    }

    public function edit(ProtocolVersion $protocol, ResourceArticle $resource): Response
    {
        return Inertia::render('Admin/Resources/Form', [
            'protocol' => $protocol->only(['id', 'code', 'name']),
            'article' => $resource->only(['id', 'section', 'title', 'body', 'sort_order']),
            'sections' => ResourceArticle::SECTIONS,
        ]);
    }

    public function update(Request $request, ProtocolVersion $protocol, ResourceArticle $resource): RedirectResponse
    {
        abort_if($protocol->isFrozen(), 403, 'Dondurulmuş protokol değiştirilemez.');

        $resource->update($this->validated($request));

        return redirect()->route('admin.protocols.resources.index', $protocol)->with('success', 'Kaynak sayfası güncellendi.');
    }

    public function destroy(ProtocolVersion $protocol, ResourceArticle $resource): RedirectResponse
    {
        abort_if($protocol->isFrozen(), 403, 'Dondurulmuş protokol değiştirilemez.');

        $resource->delete();

        return back()->with('success', 'Kaynak sayfası silindi.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'section' => ['required', Rule::in(array_keys(ResourceArticle::SECTIONS))],
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:50000'],
            'sort_order' => ['required', 'integer', 'between:0,999'],
        ]);
    }
}
