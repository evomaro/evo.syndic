<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AllocationController;
use App\Http\Controllers\ChargeCategoryController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ContextController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FinancialAccountController;
use App\Http\Controllers\FinancialExerciseController;
use App\Http\Controllers\FundCallController;
use App\Http\Controllers\FundCallScheduleController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LotController;
use App\Http\Controllers\OccupancyController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OwnerFinancePortalController;
use App\Http\Controllers\OwnershipController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ResidenceController;
use App\Http\Controllers\StructureController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');
Route::get('/verify/receipts/{token}', [ReceiptController::class, 'verify'])->middleware('throttle:30,1')->name('receipts.verify');
Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::middleware('guest')->group(function () {
    Route::get('/invitations/{token}/register', [InvitationController::class, 'register'])->name('invitations.register');
    Route::post('/invitations/{token}/register', [InvitationController::class, 'store'])->name('invitations.register.store');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding.index');
    Route::post('/onboarding/organization', [OnboardingController::class, 'organization'])->name('onboarding.organization');
    Route::post('/onboarding/residence', [OnboardingController::class, 'residence'])->name('onboarding.residence');
    Route::post('/onboarding/skip/{step}', [OnboardingController::class, 'skip'])->name('onboarding.skip');
    Route::post('/onboarding/acknowledge-ownership', [OnboardingController::class, 'acknowledgeOwnership'])->name('onboarding.ownership.acknowledge');
    Route::post('/onboarding/defer-allocations', [OnboardingController::class, 'deferAllocations'])->name('onboarding.allocations.defer');
    Route::post('/onboarding/activate', [OnboardingController::class, 'activate'])->name('onboarding.activate');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');

    Route::middleware(['verified', 'tenant'])->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::put('/context/organizations/{organization}', [ContextController::class, 'organization'])->name('context.organization');
        Route::put('/context/residences/{residence}', [ContextController::class, 'residence'])->name('context.residence');

        Route::middleware('tenant.permission:view_residences')->group(function () {
            Route::get('/residences', [ResidenceController::class, 'index'])->name('residences.index');
            Route::get('/residences/{residence}', [ResidenceController::class, 'show'])->name('residences.show');
        });
        Route::middleware('tenant.permission:create_residences')->group(function () {
            Route::get('/residences/create/new', [ResidenceController::class, 'create'])->name('residences.create');
            Route::post('/residences', [ResidenceController::class, 'store'])->name('residences.store');
        });
        Route::middleware('tenant.permission:edit_residences')->group(function () {
            Route::get('/residences/{residence}/edit', [ResidenceController::class, 'edit'])->name('residences.edit');
            Route::put('/residences/{residence}', [ResidenceController::class, 'update'])->name('residences.update');
            Route::delete('/residences/{residence}/logo', [ResidenceController::class, 'removeLogo'])->name('residences.logo.destroy');
            Route::post('/residences/{residence}/archive', [ResidenceController::class, 'archive'])->name('residences.archive');
            Route::post('/residences/{residence}/restore', [ResidenceController::class, 'restore'])->name('residences.restore');
        });

        Route::middleware('tenant.permission:manage_property_structure')->group(function () {
            Route::get('/structure', [StructureController::class, 'index'])->name('structure.index');
            Route::get('/lots/{lot}', [LotController::class, 'show'])->name('lots.show');
            Route::post('/structure/buildings', [StructureController::class, 'building'])->name('buildings.store');
            Route::post('/structure/buildings/{building}/entrances', [StructureController::class, 'entrance'])->name('entrances.store');
            Route::post('/structure/buildings/{building}/floors', [StructureController::class, 'floor'])->name('floors.store');
            Route::post('/structure/lots', [StructureController::class, 'lot'])->name('lots.store');
            Route::put('/structure/lots/{lot}', [StructureController::class, 'updateLot'])->name('lots.update');
            Route::post('/structure/lots/bulk', [StructureController::class, 'bulk'])->name('lots.bulk');
        });
        Route::get('/search/contacts', [LotController::class, 'contacts'])->middleware('tenant.permission:manage_contacts')->name('search.contacts');
        Route::middleware('tenant.permission:manage_contacts')->group(function () {
            Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
            Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
            Route::get('/contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
            Route::put('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
            Route::post('/contacts/{contact}/users/{user}', [ContactController::class, 'linkUser'])->name('contacts.users.link');
            Route::delete('/contacts/{contact}/users/{user}', [ContactController::class, 'unlinkUser'])->name('contacts.users.unlink');
        });
        Route::post('/lots/{lot}/ownership-transfer', [OwnershipController::class, 'transfer'])->middleware('tenant.permission:manage_ownerships')->name('ownerships.transfer');
        Route::post('/lots/{lot}/occupancies', [OccupancyController::class, 'store'])->middleware('tenant.permission:manage_occupancies')->name('occupancies.store');
        Route::post('/lots/{lot}/occupancies/{occupancy}/close', [OccupancyController::class, 'close'])->middleware('tenant.permission:manage_occupancies')->name('occupancies.close');
        Route::middleware('tenant.permission:manage_allocation_keys')->group(function () {
            Route::get('/allocations', [AllocationController::class, 'index'])->name('allocations.index');
            Route::post('/allocations', [AllocationController::class, 'store'])->name('allocations.store');
            Route::put('/allocations/{allocationKey}/values', [AllocationController::class, 'values'])->name('allocations.values');
            Route::post('/allocations/{allocationKey}/bulk', [AllocationController::class, 'bulk'])->name('allocations.bulk');
        });
        Route::middleware('tenant.permission:import_data')->group(function () {
            Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
            Route::post('/imports', [ImportController::class, 'upload'])->name('imports.upload');
            Route::get('/imports/{batch}/map', [ImportController::class, 'map'])->name('imports.map');
            Route::post('/imports/{batch}/confirm', [ImportController::class, 'confirm'])->name('imports.confirm');
            Route::get('/imports/{batch}/errors', [ImportController::class, 'errors'])->name('imports.errors');
            Route::post('/imports/{batch}/rollback', [ImportController::class, 'rollback'])->name('imports.rollback');
            Route::get('/import-templates/{type}', [ImportController::class, 'template'])->name('imports.template');
        });
        Route::get('/activity', ActivityController::class)->middleware('tenant.permission:view_activity_logs')->name('activity.index');
        Route::get('/my-finances', OwnerFinancePortalController::class)->name('owner-finance.index');
        Route::get('/my-finances/lots/{lot}/statement.pdf', [OwnerFinancePortalController::class, 'statement'])->name('owner-finance.statement');
        Route::get('/finance/receipts/{document}', [ReceiptController::class, 'download'])->name('receipts.download');
        Route::middleware('tenant.permission:view_finance')->group(function () {
            Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
            Route::get('/finance/search', [FinanceController::class, 'search'])->name('finance.search');
            Route::get('/finance/fund-calls', [FundCallController::class, 'index'])->name('fund-calls.index');
            Route::get('/finance/fund-calls/{fundCall}', [FundCallController::class, 'show'])->name('fund-calls.show');
            Route::get('/finance/fund-calls/{fundCall}/pdf', [FundCallController::class, 'pdf'])->name('fund-calls.pdf');
            Route::get('/finance/payments', [PaymentController::class, 'index'])->name('payments.index');
            Route::get('/finance/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
        });
        Route::middleware('tenant.permission:manage_financial_exercises')->group(function () {
            Route::get('/finance/exercises', [FinancialExerciseController::class, 'index'])->name('financial-exercises.index');
            Route::post('/finance/exercises', [FinancialExerciseController::class, 'store'])->name('financial-exercises.store');
            Route::put('/finance/exercises/{exercise}', [FinancialExerciseController::class, 'update'])->name('financial-exercises.update');
            Route::post('/finance/exercises/{exercise}/transition', [FinancialExerciseController::class, 'transition'])->name('financial-exercises.transition');
        });
        Route::middleware('tenant.permission:manage_financial_accounts')->group(function () {
            Route::get('/finance/accounts', [FinancialAccountController::class, 'index'])->name('financial-accounts.index');
            Route::post('/finance/accounts', [FinancialAccountController::class, 'store'])->name('financial-accounts.store');
            Route::put('/finance/accounts/{account}', [FinancialAccountController::class, 'update'])->name('financial-accounts.update');
            Route::post('/finance/accounts/{account}/archive', [FinancialAccountController::class, 'archive'])->name('financial-accounts.archive');
        });
        Route::middleware('tenant.permission:manage_charge_categories')->group(function () {
            Route::get('/finance/categories', [ChargeCategoryController::class, 'index'])->name('charge-categories.index');
            Route::post('/finance/categories', [ChargeCategoryController::class, 'store'])->name('charge-categories.store');
            Route::put('/finance/categories/{category}', [ChargeCategoryController::class, 'update'])->name('charge-categories.update');
            Route::post('/finance/categories/seed', [ChargeCategoryController::class, 'seed'])->name('charge-categories.seed');
        });
        Route::middleware('tenant.permission:create_fund_calls')->group(function () {
            Route::get('/finance/fund-calls/create/new', [FundCallController::class, 'create'])->name('fund-calls.create');
            Route::post('/finance/fund-calls', [FundCallController::class, 'store'])->name('fund-calls.store');
            Route::get('/finance/fund-calls/{fundCall}/edit', [FundCallController::class, 'edit'])->name('fund-calls.edit');
            Route::put('/finance/fund-calls/{fundCall}', [FundCallController::class, 'update'])->name('fund-calls.update');
            Route::get('/finance/schedules', [FundCallScheduleController::class, 'index'])->name('fund-call-schedules.index');
            Route::post('/finance/schedules', [FundCallScheduleController::class, 'store'])->name('fund-call-schedules.store');
            Route::put('/finance/schedules/{schedule}', [FundCallScheduleController::class, 'update'])->name('fund-call-schedules.update');
        });
        Route::post('/finance/fund-calls/{fundCall}/validate', [FundCallController::class, 'validateCall'])->middleware('tenant.permission:validate_fund_calls')->name('fund-calls.validate');
        Route::post('/finance/fund-calls/{fundCall}/cancel', [FundCallController::class, 'cancel'])->middleware('tenant.permission:cancel_fund_calls')->name('fund-calls.cancel');
        Route::middleware('tenant.permission:create_payments')->group(function () {
            Route::get('/finance/payments/create/new', [PaymentController::class, 'create'])->name('payments.create');
            Route::post('/finance/payments', [PaymentController::class, 'store'])->name('payments.store');
            Route::get('/finance/payments/{payment}/edit', [PaymentController::class, 'edit'])->name('payments.edit');
            Route::put('/finance/payments/{payment}', [PaymentController::class, 'update'])->name('payments.update');
        });
        Route::post('/finance/payments/{payment}/validate', [PaymentController::class, 'validatePayment'])->middleware('tenant.permission:validate_payments')->name('payments.validate');
        Route::post('/finance/payments/{payment}/allocate', [PaymentController::class, 'allocate'])->middleware('tenant.permission:allocate_credit')->name('payments.allocate');
        Route::post('/finance/payments/{payment}/identify-payer', [PaymentController::class, 'identifyPayer'])->middleware('tenant.permission:allocate_credit')->name('payments.identify-payer');
        Route::post('/finance/payments/{payment}/reverse', [PaymentController::class, 'reverse'])->middleware('tenant.permission:reverse_payments')->name('payments.reverse');
        Route::post('/finance/payments/{payment}/receipt/retry', [ReceiptController::class, 'retry'])->middleware('tenant.permission:validate_payments')->name('receipts.retry');
        Route::get('/finance/outstanding', [FinanceController::class, 'outstanding'])->middleware('tenant.permission:view_outstanding')->name('finance.outstanding');
        Route::get('/finance/statements', [FinanceController::class, 'statements'])->middleware('tenant.permission:view_statements')->name('finance.statements');
        Route::get('/finance/statements/pdf', [FinanceController::class, 'statementPdf'])->middleware('tenant.permission:export_finance')->name('finance.statements.pdf');
        Route::get('/finance/statements/csv', [FinanceController::class, 'statementCsv'])->middleware('tenant.permission:export_finance')->name('finance.statements.csv');
        Route::get('/finance/outstanding/export', [FinanceController::class, 'exportOutstanding'])->middleware('tenant.permission:export_finance')->name('finance.outstanding.export');
        Route::middleware('tenant.permission:manage_team')->group(function () {
            Route::get('/team', [TeamController::class, 'index'])->name('team.index');
            Route::post('/team/invitations', [TeamController::class, 'invite'])->name('team.invite');
            Route::post('/team/invitations/{invitation}/cancel', [TeamController::class, 'cancel'])->name('team.cancel');
            Route::post('/team/invitations/{invitation}/resend', [TeamController::class, 'resend'])->name('team.resend');
            Route::put('/team/members/{user}/role', [TeamController::class, 'role'])->name('team.role');
        });
    });
});

require __DIR__.'/auth.php';
