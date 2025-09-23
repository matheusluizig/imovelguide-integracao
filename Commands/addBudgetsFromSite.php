<?php

namespace App\Console\Commands;

use App\Http\Controllers\BudgetController;
use Illuminate\Console\Command;
use Mail;

class addBudgetsFromSite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'budget:add';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Adicionar os projetos no site';

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
        $BudgetController = new BudgetController;
        $BudgetController->addBudgetsFromSite();

        // Mail::send('contatoAdminSimples', ['messageText' => "Atualização dos valores de metro quadrado dos bairros"], function ($m){
        //     $m->from('naoresponda@imovelguide.com.br', 'Imóvel Guide');
        //     $m->to('admin@imovelguide.com.br', 'Imóvel Guide');
        //     $m->bcc("sentimovelguide@gmail.com", 'Imóvel Guide');
        //     $m->subject('Valor m² | Processamento Status');
        // });
    }
}
