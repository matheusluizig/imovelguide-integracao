<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\BairrosDeController;

class autoAddCities extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auto:addcities';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Comando que adiciona cidades a tabelas';

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
        $cidades = new BairrosDeController;
        $cidades = $cidades->tableCityInsert();
    }
}
