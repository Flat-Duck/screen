<?php

namespace App\Livewire;

use App\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ContentTable extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $state = '';

    #[Url]
    public string $recommendation = '';

    #[Url]
    public string $reported = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $posts = Post::withTrashed()->with(['user', 'media'])->withCount(['likes', 'comments'])
            ->when($this->search !== '', fn ($query) => $query->where(fn ($search) => $search->where('caption', 'like', '%'.$this->search.'%')->orWhere('id', ctype_digit($this->search) ? (int) $this->search : -1)))
            ->when($this->state === 'removed', fn ($query) => $query->onlyTrashed())
            ->when($this->state === 'active', fn ($query) => $query->withoutTrashed())
            ->when($this->recommendation !== '', fn ($query) => $query->where('recommendation_eligible', $this->recommendation === 'eligible'))
            ->when($this->reported === 'yes', fn ($query) => $query->whereHas('reports'))
            ->latest('id')->paginate(20);

        return view('livewire.content-table', ['posts' => $posts]);
    }
}
