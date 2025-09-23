<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use App\Services\Sitemap\Categories\BaseSitemap;

use Illuminate\Support\Facades\DB;

class ProccessSitemap implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(public BaseSitemap $sitemap, private array $links = [])
    {
        $this->sitemap = $sitemap;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        set_time_limit(0);
        ini_set('allow_url_fopen', 1);
        ini_set('memory_limit', -1);

        DB::table('sitemaps')->where('category', $this->sitemap->getCategory()->value)->delete();
        $this->sitemap->generateSitemaps();
        foreach ($this->sitemap->getSitemaps() as $sitemapUrl) {
            $this->links[] = [
                'path_file' => $sitemapUrl,
                'category' => $this->sitemap->getCategory()->value
            ];
        }

        DB::table('sitemaps')->insert($this->links);
        return;
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array
    */
    public function middleware() {
        return [(new WithoutOverlapping($this->sitemap->getCategory()->value))->dontRelease()];
    }
}
