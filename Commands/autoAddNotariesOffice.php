<?php

namespace App\Console\Commands;

use App\Http\Controllers\AutoNotariesController;
use Illuminate\Console\Command;

class autoAddNotariesOffice extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notaries:office';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Função que adiciona mais endereços de cartórios';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $notaries = new AutoNotariesController();
        $notaries = $notaries->notaryOfficeAdd();

    }
}
