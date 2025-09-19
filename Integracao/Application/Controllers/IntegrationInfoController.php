<?php

namespace App\Integracao\Application\Controllers;

use Illuminate\Http\Request;

class IntegrationInfoController extends Controller
{
    public function integracaoPage()
    {
        return view('integracao.index');
    }

    public function integracaoTutorial()
    {
        return view('integracaoTutorial');
    }
}
