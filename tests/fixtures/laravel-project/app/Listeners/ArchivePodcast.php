<?php

namespace App\Listeners;

use App\Events\PodcastArchived;
use Illuminate\Events\Attributes\AsEventListener;

class ArchivePodcast
{
    #[AsEventListener(PodcastArchived::class, 'archive')]
    public function archive(): void {}
}
