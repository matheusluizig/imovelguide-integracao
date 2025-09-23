<?php

namespace App\Console\Commands;

use App\Http\Controllers\forumController;
use Illuminate\Console\Command;
use Mail;

class AttBestAnswers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'att:best-answers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Comando para atualizar as melhores respostas no forum';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(private forumController $forumController)
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
        $this->forumController->validateBestAnswers();
        Mail::send('contatoAdminSimples', ['messageText' => "Atualização das melhores respostas do forum concluída"], function ($m){
            $m->from('naoresponda@imovelguide.com.br', 'Imóvel Guide');
            $m->to('admin@imovelguide.com.br', 'Imóvel Guide');
            $m->bcc("sentimovelguide@gmail.com", 'Imóvel Guide');
            $m->subject('Att Best Answers | Processamento Status');
        });
    }
}
