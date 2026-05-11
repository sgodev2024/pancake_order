<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class ProductByShopChunkJob implements ShouldQueue
{
    use Queueable;

    protected $shop_id;

    protected $api_key;

    protected $pages;

    protected $page_size;

    protected $apiUrl;

    protected $pancake_shop_id;

    /**
     * Create a new job instance.
     */
    public function __construct(
        $shop_id,
        $pancake_shop_id,
        $api_key,
        $pages,
        $page_size,
        $apiUrl
    )
    {
        $this->shop_id         = $shop_id;
        $this->pancake_shop_id = $pancake_shop_id;
        $this->api_key         = $api_key;
        $this->pages           = $pages;
        $this->page_size       = $page_size;
        $this->apiUrl          = $apiUrl;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        foreach ($this->pages as $page) {
            $response = Http::get($this->apiUrl . "shops/{$this->pancake_shop_id}/products/variations?api_key={$this->api_key}&page_size={$this->page_size}&page_number={$page}")->json();
            if (!empty($response["data"])) {
                GetProductByShopJob::dispatch(
                    $response["data"],
                    $this->shop_id
                )->onQueue("get-product");
            }
        }

        return;
    }
}
