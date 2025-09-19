<?php

namespace App\Integracao\Application\Controllers;

// Services.
use App\Integracao\Application\Services\IntegrationValidationService;
use App\Integracao\Application\Services\IntegrationManagementService;
// Support.
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\User;
use App\Crm;
use Auth;
use Session;
// Models.
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Domain\Entities\Integracao;
// Jobs.
use App\Integracao\Application\Jobs\ProcessIntegrationJob;

class XMLIntegrationController extends Controller
{
    private IntegrationValidationService $validationService;
    private IntegrationManagementService $managementService;

    public function __construct(
        IntegrationValidationService $validationService,
        IntegrationManagementService $managementService
    ) {
        $this->validationService = $validationService;
        $this->managementService = $managementService;
    }

    public function index() {
        $xml = Integracao::select('id', 'user_id', 'link', 'status', 'system', 'updated_at')
            ->with(['user:id,name,email'])
            ->where('user_id', '=', Auth::user()->id)
            ->first();

        $crms = Crm::select('id', 'crm_name', 'star')
            ->where('inactivate', '=', 0)
            ->orderBy('star', 'desc')
            ->orderBy('crm_name', 'asc')
            ->get();

        return view('painel.dashboard.integracao', compact('crms', 'xml'));
    }


    public function indexAdmin() {
        $crms = Crm::select('id', 'crm_name', 'star')
            ->where('inactivate', '=', 0)
            ->orderBy('star', 'desc')
            ->orderBy('crm_name', 'asc')
            ->get();
        return view('admin.dashboard.integration.create', compact('crms'));
    }

    public function storeByUser(Request $request) {
        $parsedUrl = preg_replace('/\s*/m', '', $request->url);
        $user = Auth::user();

        // Caso especial: remover XML
        if (empty($parsedUrl)) {
            DB::table('integracao_xml')->where('user_id', $user->id)->delete();
            Session::put('Sucesso', "Seu Arquivo XML foi apagado com sucesso! Fique tranquilo, todos seus imóveis foram salvos em nosso portal.");
            return redirect()->back();
        }

        // NOVA VALIDAÇÃO: Validar XML antes de criar registro
        $validationResult = $this->validationService->validateIntegration($parsedUrl);

        if (!$validationResult->isValid()) {
            $errorMessage = 'XML inválido: ' . implode(', ', $validationResult->getErrors());
            Session::put('Erro', $errorMessage);
            return redirect()->back();
        }

        // Criar integração usando o novo serviço
        $integrationResult = $this->managementService->createIntegration([
            'user_id' => $user->id,
            'url' => $parsedUrl,
            'force' => isset($request->force)
        ]);

        if ($integrationResult->isSuccess()) {
            Session::put('Sucesso', $integrationResult->getMessage());
        } else {
            Session::put('Erro', $integrationResult->getMessage());
        }

        return redirect()->back();
    }

    public function storeByAdmin(Request $request) {
        $user = User::find($request->user);
        if (!$user) {
            Session::put('Erro', 'Usuário não encontrado!');
            return redirect()->back();
        }

        $parsedUrl = preg_replace('/\s*/m', '', $request->url);

        // Caso especial: remover XML
        if (empty($parsedUrl)) {
            DB::table('integracao_xml')->where('user_id', $user->id)->delete();
            Session::put('Sucesso', "Arquivo XML foi apagado com sucesso!");
            return redirect()->back();
        }

        // NOVA VALIDAÇÃO: Validar XML antes de criar registro
        $validationResult = $this->validationService->validateIntegration($parsedUrl);

        if (!$validationResult->isValid()) {
            $errorMessage = 'XML inválido: ' . implode(', ', $validationResult->getErrors());
            Session::put('Erro', $errorMessage);
            return redirect()->back();
        }

        // Validar CRM se fornecido
        $crmId = null;
        if (!empty($request->crmInput)) {
            $crm = Crm::where('crm_name', '=', $request->crmInput)->first();
            if ($crm) {
                $crmId = $crm->id;
            } else {
                Session::put('Erro', 'O CRM que você inseriu não foi encontrado. Não foi possível prosseguir!');
                return redirect()->back();
            }
        }

        // Criar integração usando o novo serviço
        $integrationResult = $this->managementService->createIntegration([
            'user_id' => $user->id,
            'url' => $parsedUrl,
            'crm_id' => $crmId
        ]);

        if ($integrationResult->isSuccess()) {
            Session::put('Sucesso', 'Arquivo XML foi cadastrado/atualizado!');
        } else {
            Session::put('Erro', $integrationResult->getMessage());
        }

        return redirect()->back();
    }

    public function delete(Request $request) {
        $result = Integracao::where('id', $request->id)->delete();
        return response()->json(['result' => $result]);
    }

    public function remove(Request $request) {
        $result = Integracao::with('queue')->where('id', $request->id)->first();
        if ($request->fromAnalysis) {
            if ($result->queue) {
                if ($result->queue->status != IntegrationsQueues::STATUS_IN_PROCESS) {
                    $result->queue->status = IntegrationsQueues::STATUS_STOPPED;
                    $result->queue->save();
                }
            }
        }

        $result = $result->update([
            'status' => Integracao::XML_STATUS_IGNORED
        ]);

        return response()->json(['result' => $result]);
    }

    public function approve(Request $request) {
        $result = Integracao::with('queue', 'user')->where('id', $request->id)->first();

        if ($result->queue) {
            $result->queue->status = IntegrationsQueues::STATUS_PENDING;
            $result->queue->save();
        } else {
            IntegrationsQueues::create([
                'integration_id' => $result->id,
                'priority' => $result->user->integration_priority,
                'status' => IntegrationsQueues::STATUS_PENDING,
            ]);
        }

        $result = $result->update([
            'status' => Integracao::XML_STATUS_NOT_INTEGRATED
        ]);

        return response()->json(['result' => $result]);
    }

    public function restore(Request $request) {
        $result = Integracao::with('queue', 'user')->where('id', $request->id)->first();
        if ($request->forceUpdate === "true") {

            if ($result->queue) {
                $result->queue->status = IntegrationsQueues::STATUS_DONE;
                $result->queue->save();
            } else {
                IntegrationsQueues::create([
                    'integration_id' => $result->id,
                    'priority' => $result->user->integration_priority,
                    'status' => IntegrationsQueues::STATUS_DONE,
                ]);
            }

            $result = $result->update([
                'status' => Integracao::XML_STATUS_INTEGRATED
            ]);
            return response()->json(['result' => "XML sendo atualizado"]);
        }

        if ($result->queue) {
            $result->queue->status = IntegrationsQueues::STATUS_DONE;
            $result->queue->save();
        } else {
            IntegrationsQueues::create([
                'integration_id' => $result->id,
                'priority' => $result->user->integration_priority,
                'status' => IntegrationsQueues::STATUS_IN_PROCESS,
            ]);
        }

        $result = $result->update([
            'status' => Integracao::XML_STATUS_INTEGRATED
        ]);

        ProcessIntegrationJob::dispatch($request->id, 'priority-integrations');
        return response()->json(['result' => "XML sendo atualizado"]);
    }


    public function backToProgrammers(Request $request) {
        $result = Integracao::where('id', $request->id)->first();
        $result = $result->update([
            'status' => Integracao::XML_STATUS_PROGRAMMERS_SOLVE
        ]);

        return response()->json(['result' => $result]);
    }

    public function moveToCategory(Request $request) {
        $result = Integracao::where('id', $request->id)->first();
        $result = $result->update([
            'status' => (int)$request->category
        ]);

        return response()->json(['result' => $result]);
    }

    // NOVOS MÉTODOS PARA VISÃO GERAL DA FILA
    public function getQueueOverview() {
        $overview = $this->managementService->getQueueOverview();
        return response()->json([
            'success' => true,
            'data' => $overview->toArray()
        ]);
    }

    public function skipIntegration(Request $request) {
        $success = $this->managementService->skipIntegration(
            $request->integration_id,
            $request->reason ?? 'Pulada manualmente'
        );

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Integração pulada com sucesso' : 'Erro ao pular integração'
        ]);
    }
}