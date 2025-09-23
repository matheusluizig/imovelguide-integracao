<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\ResetQtdEditorImage;
use App\Jobs\ProcessSiteGratisAssinaturaAsaas;

class Kernel extends ConsoleKernel
{
  protected $commands = [
    Commands\GenerateTag::class,
    Commands\AttBestAnswers::class,
    Commands\SaveNewQuestionsOnForum::class,
    Commands\AutoTriggerInviteMassEmail::class,
    Commands\AutoTriggerCapturedLeadEmail::class,
    Commands\SocialNetworkReadjustPostPoints::class,
    Commands\generateM2ValueOfApartmentsMonthly::class,
    Commands\generateTagsForTopTen::class,
    Commands\addBudgetsFromSite::class,
    Commands\AddDecorationsFromSite::class,
    Commands\PublishAnswersFromDistricts::class,
    Commands\ReleaseForumUserBlocked::class,
    Commands\ChangeStatusByCertificate::class,
    Commands\generateRankM2::class,
    Commands\ClearLeadsDistributedUserMonthly::class,
    Commands\UploadImagesFromDecoration::class,
    Commands\SendEmailSecretary::class,
    Commands\ScheduleSecretaryEmail::class,
    \App\Integracao\Application\Commands\ReprocessAllIntegrations::class,
    \App\Integracao\Application\Commands\ReprocessFailedIntegrations::class,
    Commands\SaveUserDistrictAds::class,
    Commands\autoStreets::class,
    Commands\autoAddNotariesProperty::class,
    Commands\autoAddNotariesOffice::class,
    Commands\autoAddNotariesWedding::class,
    Commands\autoAddTagOnFooter::class,
    Commands\autoAddSchool::class,
    Commands\autoAddCities::class,
    Commands\SaveConsultationBroker::class,
    Commands\ExpiredPlanChecker::class,
    Commands\PlansUpdateQueue::class,
    \App\Integracao\Application\Commands\ManageIntegrationWorker::class,
    \App\Integracao\Application\Commands\DispatchPriorityIntegration::class,
    \App\Integracao\Application\Commands\ClearIntegrationSlots::class,
    Commands\GenerateSitemapLinks::class,
    Commands\GenerateSitemapIndex::class,
    Commands\UpdateAnswersConstructionForum::class,
    Commands\AtualizarSites::class,
    Commands\GenerateTopUsersTable::class,
    Commands\AtualizarPontosLogin::class,
    Commands\RefreshAnunciosCache::class,
    Commands\RefreshCachedDepoimentos::class,
    Commands\ProcessSiteGratisAssinatura::class,
    \App\Console\Commands\AtualizarAssinaturas::class,
    Commands\JwtGenerateSecret::class,
    \App\Integracao\Application\Commands\IntegrationMetricsCommand::class,
    \App\Integracao\Application\Commands\IntegrationLogAnalyzer::class,
    \App\Integracao\Application\Commands\IntegrationLogCleanup::class,
    \App\Integracao\Application\Commands\IntegrationDashboard::class,
    \App\Integracao\Application\Commands\AutomaticPlanIntegration::class,
    \App\Integracao\Application\Commands\AutomaticNormalIntegration::class,
    \App\Integracao\Application\Commands\AutomaticLevelIntegration::class,
    \App\Integracao\Application\Commands\TestIntegrationFlow::class,
    \App\Integracao\Application\Commands\DiagnoseIntegrationJobs::class,
    Commands\UpdateNegotiationId::class,
    Commands\AnalyticsAggregate::class,
    Commands\ClearOldLogs::class,
    Commands\PurgeDeletedAdsCommand::class,
    Commands\ClearRankingsCache::class,
    Commands\ClearGuideCache::class,
    Commands\ClearConsultationCache::class,
    Commands\ClearTenantsCache::class,
  ];

  protected function schedule(Schedule $schedule)
  {
    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    // RODAM A CADA MINUTO
    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    // $schedule->command('autoEmail:invite')->withoutOverlapping()->everyMinute();

    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    // RODAM A CADA 10 MIN
    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    $schedule
      ->command('auto:plansUpdateQueue')
      ->everyTenMinutes()
      ->timezone('America/Sao_Paulo');

    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    // RODAM A CADA 15 MIN
    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    $schedule
      ->command('atualizarAssinaturas')
      ->everyFifteenMinutes()
      ->withoutOverlapping()
      ->timezone('America/Sao_Paulo');

    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    // RODAM A CADA 30 MIN
    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    // RODAM A CADA 30 MIN
    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    // CRON DESABILITADO - USAR APENAS SUPERVISOR WORKER
    // Para reprocessar integrações, use: php artisan integration:reprocess-all
    //
    // $schedule
    //   ->command('fila:processar')
    //   ->everyThirtyMinutes()
    //   ->withoutOverlapping()
    //   ->runInBackground();

    $schedule
      ->command('autoEmail:capturedLead')
      ->everyThirtyMinutes()
      ->withoutOverlapping()
      ->runInBackground();

    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    // RODAM A CADA HORA
    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    $schedule
      ->command('redis:monitor')
      ->hourly()
      ->withoutOverlapping()
      ->runInBackground();

    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    // RODAM A CADA 2 HORAS
    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    // INTEGRAÇÕES DE PLANO (PRIORIDADE MÁXIMA) - A cada 2 horas
    $schedule
      ->command('auto:automaticPlanIntegration')
      ->everyTwoHours()
      ->withoutOverlapping()
      ->onOneServer()
      ->runInBackground()
      ->timezone('America/Sao_Paulo');

    // INTEGRAÇÕES DE NÍVEL (PRIORIDADE MÉDIA) - A cada 4 horas
    $schedule
      ->command('auto:automaticLevelIntegration')
      ->cron('0 */4 * * *')
      ->withoutOverlapping()
      ->onOneServer()
      ->runInBackground()
      ->timezone('America/Sao_Paulo');

    // INTEGRAÇÕES NORMAIS (PRIORIDADE BAIXA) - A cada 15 dias
    $schedule
      ->command('auto:automaticNormalIntegration')
      ->cron('0 2 */15 * *')
      ->withoutOverlapping()
      ->onOneServer()
      ->runInBackground()
      ->timezone('America/Sao_Paulo');

    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    // RODAM A CADA 6 HORAS
    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    $schedule
      ->job(ProcessSiteGratisAssinaturaAsaas::class)
      ->cron('0 */6 * * *');

    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    // MODO LIGHT - FREQUÊNCIAS OTIMIZADAS
    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    // PLANO (LIGHT) - 2x ao dia (manhã e tarde)
    $schedule
      ->command('auto:automaticPlanIntegration --light')
      ->cron('0 8,20 * * *')
      ->withoutOverlapping()
      ->onOneServer()
      ->runInBackground()
      ->timezone('America/Sao_Paulo');

    // NÍVEL (LIGHT) - 1x ao dia (meio-dia)
    $schedule
      ->command('auto:automaticLevelIntegration --light')
      ->cron('0 12 * * *')
      ->withoutOverlapping()
      ->onOneServer()
      ->runInBackground()
      ->timezone('America/Sao_Paulo');

    // NORMAL (LIGHT) - 1x a cada 7 dias (domingo)
    $schedule
      ->command('auto:automaticNormalIntegration --light')
      ->cron('0 2 * * 0')
      ->withoutOverlapping()
      ->onOneServer()
      ->runInBackground()
      ->timezone('America/Sao_Paulo');

    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    // RODAM DIARIAMENTE
    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    // $schedule->command('tag:generate')->dailyAt('00:00');

    $schedule
      ->command('autoChange:secretary')
      ->dailyAt('00:00');

    // $schedule->command('analytics:aggregate')->dailyAt('00:00')->withoutOverlapping()->runInBackground();

    $schedule
      ->command('consultation:aggregate')
      ->dailyAt('00:00');

    $schedule
      ->command('queue:flush')
      ->dailyAt('00:00')
      ->withoutOverlapping()
      ->runInBackground();

    $schedule
      ->command('queue:prune-batches')
      ->dailyAt('00:00')
      ->withoutOverlapping()
      ->runInBackground();

    $schedule
      ->command('update:negotiation_id')
      ->dailyAt('00:01')
      ->timezone('America/Sao_Paulo');

    $schedule
      ->command('app:invalidate-analytics-cache')
      ->dailyAt('00:30')
      ->withoutOverlapping();

    $schedule
      ->command('att:best-answers')
      ->dailyAt('00:40');

    $schedule
      ->command('sitemap:generateLinks')
      ->dailyAt('01:00')
      ->timezone('America/Sao_Paulo');

    // $schedule->command('cache:regenerate --type=condominios')->withoutOverlapping()->dailyAt('01:00')->runInBackground();

    $schedule
      ->command('pontos:atualizar')
      ->dailyAt('01:00')
      ->withoutOverlapping()
      ->runInBackground();

    $schedule
      ->command('cache:regenerate --type=anuncios')
      ->withoutOverlapping()
      ->dailyAt('01:30')
      ->runInBackground();

    $schedule
      ->command('ranking:generate-top-users-table')
      ->dailyAt('01:30')
      ->withoutOverlapping();

    $schedule
      ->command('depoimentos:refresh-cache')
      ->withoutOverlapping()
      ->dailyAt('02:00')
      ->runInBackground();

    $schedule
      ->command('auto:expiredPlanChecker')
      ->dailyAt('02:00')
      ->withoutOverlapping()
      ->timezone('America/Sao_Paulo');

    $schedule
      ->command('cache:regenerate --type=comentarios')
      ->withoutOverlapping()
      ->dailyAt('02:30')
      ->runInBackground();

    $schedule
      ->command('cache:regenerate --type=users')
      ->withoutOverlapping()
      ->dailyAt('03:00')
      ->runInBackground();

    $schedule
      ->job(new \App\Jobs\UpdateUserRankingsJob())
      ->dailyAt('03:00')
      ->withoutOverlapping()
      ->timezone('America/Sao_Paulo');

    $schedule
      ->command('autoEmail:secretary')
      ->dailyAt('03:30')
      ->withoutOverlapping()
      ->runInBackground();

    $schedule
      ->command('sitemap:generateIndex')
      ->dailyAt('07:00')
      ->timezone('America/Sao_Paulo');

    // $schedule->command('decoration:add')->dailyAt('08:00');

    $schedule
      ->command('anuncios:refresh-cache')
      ->dailyAt('12:00')
      ->withoutOverlapping()
      ->runInBackground();

    $schedule
      ->command('autoStreets:register')
      ->dailyAt('16:00');

    // $schedule->command('certificate:add')->dailyAt('17:00');

    $schedule
      ->command('userDistrictAds:save')
      ->dailyAt('22:00');

    $schedule
      ->command('integration:updateDataDaily 3')
      ->withoutOverlapping()
      ->dailyAt('22:35')
      ->timezone('America/Sao_Paulo');

    $schedule
      ->command('tagTen:generate')
      ->dailyAt('23:00');

    // $schedule->command('budget:add')->dailyAt('23:20');

    $schedule
      ->command('forumQuestions:save')
      ->dailyAt('23:30');

    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    // RODAM SEMANALMENTE
    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    // $schedule->command('anuncio:aggregate-views --force')->weeklyOn(0, '02:00')->withoutOverlapping()->timezone('America/Sao_Paulo');

    $schedule
      ->command('socialNetwork:attPostPoints')
      ->weeklyOn(6, '03:00')
      ->withoutOverlapping()
      ->runInBackground();

    $schedule
      ->command('db:optimize-indexes')
      ->weeklyOn(0, '05:00')
      ->appendOutputTo(storage_path('logs/db-index-optimize.log'))
      ->runInBackground();

    $schedule
      ->command('forumUser:release')
      ->weeklyOn(2, '09:00');

    $schedule
      ->command('auto:addconsultationbroker')
      ->weeklyOn(0, '10:00');

    $schedule
      ->command('notaries:property')
      ->weeklyOn(0, '10:10');

    $schedule
      ->command('notaries:wedding')
      ->weeklyOn(0, '10:15');

    $schedule
      ->command('notaries:office')
      ->weeklyOn(0, '10:20');

    $schedule
      ->command('autoadd:school')
      ->weeklyOn(6, '10:25');

    $schedule
      ->command('add:tag')
      ->weeklyOn(6, '12:00');

    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    // RODAM A CADA 15 DIAS
    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    $schedule
      ->command('logs:clear-old')
      ->cron('0 0 */15 * *')
      ->withoutOverlapping()
      ->runInBackground();

    $schedule
      ->command('integration:log-cleanup --days=30 --compress')
      ->dailyAt('02:00')
      ->withoutOverlapping()
      ->runInBackground();
    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    // RODAM MENSALMENTE
    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    $schedule
      ->command('integration:updateAllImageDaily 607 1212')
      ->withoutOverlapping()
      ->monthlyOn(7, '00:00')
      ->timezone('America/Sao_Paulo')
      ->runInBackground();

    $schedule
      ->command('leadsDistributedUserMonthly:clear')
      ->monthlyOn(1, '00:00');

    $schedule
      ->command('m2rank:generate')
      ->monthlyOn(1, '00:20');

    $schedule
      ->command('areaValue:generate')
      ->monthlyOn(1, '00:30');

    $schedule
      ->command('auto:addcities')
      ->monthlyOn(1, '00:40');

    $schedule
      ->command('integration:updateAllImageDaily 0 606')
      ->withoutOverlapping()
      ->monthlyOn(1, '1:00')
      ->timezone('America/Sao_Paulo')
      ->runInBackground();

    $schedule
      ->command('integration:updateAllImageDaily 1213 1818')
      ->withoutOverlapping()
      ->monthlyOn(13, '02:00')
      ->timezone('America/Sao_Paulo')
      ->runInBackground();

    $schedule
      ->command('integration:updateAllImageDaily 1819 2425')
      ->withoutOverlapping()
      ->monthlyOn(19, '03:00')
      ->timezone('America/Sao_Paulo')
      ->runInBackground();

    $schedule
      ->command('integration:updateAllImageDaily 2426')
      ->withoutOverlapping()
      ->monthlyOn(25, '04:00')
      ->timezone('America/Sao_Paulo')
      ->runInBackground();

    $schedule
      ->job(ResetQtdEditorImage::class, 'resetQtdEditImgs')
      ->monthlyOn(1, '05:00');

    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    // RODAM TRIMESTRALMENTE
    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    $schedule
      ->command('anuncios:purge-deleted-ads')
      ->cron('0 3 1 */3 *')
      ->withoutOverlapping()
      ->runInBackground();
  }

  protected function commands()
  {
    $this->load(__DIR__ . '/Commands');

    require base_path('routes/console.php');
  }
}