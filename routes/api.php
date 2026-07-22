<?php

use App\Http\Controllers\Api\V1\Platform\AdminTenantRequestController;
use App\Http\Controllers\Api\V1\Platform\CashRegisterController;
use App\Http\Controllers\Api\V1\Platform\CashSettingsController;
use App\Http\Controllers\Api\V1\Platform\CatalogCategoryController;
use App\Http\Controllers\Api\V1\Platform\CatalogItemController;
use App\Http\Controllers\Api\V1\Platform\CommercialDashboardController;
use App\Http\Controllers\Api\V1\Platform\CommercialSalesReportController;
use App\Http\Controllers\Api\V1\Platform\FiscalSyncController;
use App\Http\Controllers\Api\V1\Platform\FollowUpNoteController;
use App\Http\Controllers\Api\V1\Platform\GlobalUserController;
use App\Http\Controllers\Api\V1\Platform\InternalNotificationController;
use App\Http\Controllers\Api\V1\Platform\InventoryCountController;
use App\Http\Controllers\Api\V1\Platform\InventoryLotController;
use App\Http\Controllers\Api\V1\Platform\InventoryMovementController;
use App\Http\Controllers\Api\V1\Platform\InventoryPurchaseController;
use App\Http\Controllers\Api\V1\Platform\InventoryReportController;
use App\Http\Controllers\Api\V1\Platform\InventoryReservationController;
use App\Http\Controllers\Api\V1\Platform\InventorySaleController;
use App\Http\Controllers\Api\V1\Platform\InventorySupplierController;
use App\Http\Controllers\Api\V1\Platform\InventoryTransferController;
use App\Http\Controllers\Api\V1\Platform\PlatformAuditLogController;
use App\Http\Controllers\Api\V1\Platform\SubscriptionController;
use App\Http\Controllers\Api\V1\Platform\TenantFiscalAssignmentController;
use App\Http\Controllers\Api\V1\Platform\TenantInvitationController;
use App\Http\Controllers\Api\V1\Platform\TenantLookupController;
use App\Http\Controllers\Api\V1\Platform\TenantMembershipController;
use App\Http\Controllers\Api\V1\Platform\TenantPurgeController;
use App\Http\Controllers\Api\V1\Platform\TenantRequestController;
use App\Http\Controllers\Api\V1\Platform\TenantUserController;
use App\Http\Controllers\Api\V1\Platform\UserProfileController;
use App\Http\Controllers\Api\V1\Platform\UserSecurityController;
use App\Http\Controllers\Api\V1\Platform\WorkshopOrderController;
use App\Http\Controllers\Api\V1\Platform\WorkshopTicketSettingsController;
use App\Http\Controllers\Api\V1\PlatformSessionController;
use App\Http\Controllers\Api\V1\WompiWebhookController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\PlatformAdmin\CoreProxyController;
use App\Http\Controllers\PlatformAdmin\CoreSessionController;
use App\Http\Controllers\PlatformAdmin\NotificationProxyController;
use App\Http\Controllers\PlatformAdmin\TenantAppOnboardingController;
use App\Http\Controllers\PublicWorkshopDeviceController;
use App\Http\Controllers\PublicWorkshopPhotoController;
use App\Http\Middleware\EnsurePasswordIsChanged;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('workshop/device-access/{token}')->middleware('throttle:30,1')->group(function (): void {
        Route::post('unlock', [PublicWorkshopDeviceController::class, 'unlock']);
        Route::get('secret', [PublicWorkshopDeviceController::class, 'reveal']);
        Route::patch('status', [PublicWorkshopDeviceController::class, 'updateStatus']);
    });
    Route::post('workshop/photo-upload/{token}', [PublicWorkshopPhotoController::class, 'store'])->middleware('throttle:20,1');
    Route::delete('workshop/photo-upload/{token}/{photo}', [PublicWorkshopPhotoController::class, 'destroy'])->middleware('throttle:20,1');
    Route::get('health', fn () => response()->json([
        'status' => 'ok',
        'service' => config('app.name', 'Stelfaro Platform API'),
        'version' => app()->version(),
        'timestamp' => now()->toISOString(),
    ]));

    Route::post('webhooks/wompi', WompiWebhookController::class);

    Route::middleware(['web', 'auth', 'verified', EnsurePasswordIsChanged::class])->group(function (): void {
        Route::get('me', PlatformSessionController::class);
        Route::get('me/profile', [UserProfileController::class, 'show']);
        Route::patch('me/profile', [UserProfileController::class, 'update']);
        Route::put('me/password', [UserProfileController::class, 'password']);
        Route::get('me/security', [UserSecurityController::class, 'index']);
        Route::delete('me/security/sessions/{sessionId}', [UserSecurityController::class, 'destroy']);
        Route::post('me/security/sessions/revoke-others', [UserSecurityController::class, 'destroyOthers']);
        Route::get('platform/notifications', [InternalNotificationController::class, 'index']);
        Route::post('platform/notifications/read-all', [InternalNotificationController::class, 'readAll']);
        Route::post('platform/notifications/{notification}/read', [InternalNotificationController::class, 'read']);
        Route::delete('platform/notifications/{notification}', [InternalNotificationController::class, 'destroy']);
        Route::patch('me/active-membership/{membership}', [TenantMembershipController::class, 'setActive']);
        Route::get('admin/platform/users', [GlobalUserController::class, 'index']);
        Route::get('admin/platform/audit-logs', [PlatformAuditLogController::class, 'index']);
        Route::get('admin/platform/subscriptions', [SubscriptionController::class, 'index']);
        Route::get('admin/platform/requests', [AdminTenantRequestController::class, 'index']);
        Route::patch('admin/platform/requests/{tenantRequest}', [AdminTenantRequestController::class, 'update']);
        Route::put('admin/platform/tenants/{tenant}/subscription', [SubscriptionController::class, 'update']);
        Route::get('admin/platform/tenants/by-core-empresa/{coreEmpresaId}/subscription', [SubscriptionController::class, 'showByCoreEmpresa']);
        Route::put('admin/platform/tenants/by-core-empresa/{coreEmpresaId}/subscription', [SubscriptionController::class, 'updateByCoreEmpresa']);
        Route::get('admin/platform/tenants/by-core-empresa/{coreEmpresaId}', [TenantLookupController::class, 'byCoreEmpresa']);
        Route::delete('admin/platform/tenants/by-core-empresa/{coreEmpresaId}', [TenantPurgeController::class, 'destroyByCoreEmpresa']);
        Route::post('admin/platform/tenants/by-core-empresa/{coreEmpresaId}/purge', [TenantPurgeController::class, 'destroyByCoreEmpresa']);
        Route::get('admin/core/session', CoreSessionController::class);
        Route::get('admin/platform/apps', [TenantAppOnboardingController::class, 'apps']);
        Route::post('admin/platform/tenants', [TenantAppOnboardingController::class, 'store']);
        Route::get('platform/tenants/{tenant}/users', [TenantUserController::class, 'index']);
        Route::get('platform/tenants/{tenant}/requests', [TenantRequestController::class, 'index']);
        Route::post('platform/tenants/{tenant}/requests', [TenantRequestController::class, 'store']);
        Route::get('platform/tenants/{tenant}/audit-logs', [PlatformAuditLogController::class, 'tenant']);
        Route::get('platform/tenants/{tenant}/catalog/categories', [CatalogCategoryController::class, 'index']);
        Route::post('platform/tenants/{tenant}/catalog/categories', [CatalogCategoryController::class, 'store']);
        Route::patch('platform/tenants/{tenant}/catalog/categories/{category}', [CatalogCategoryController::class, 'update']);
        Route::delete('platform/tenants/{tenant}/catalog/categories/{category}', [CatalogCategoryController::class, 'destroy']);
        Route::get('platform/tenants/{tenant}/catalog/items', [CatalogItemController::class, 'index']);
        Route::prefix('platform/tenants/{tenant}/fiscal-sync')
            ->middleware('tenant.app:facturacion')
            ->group(function (): void {
                Route::post('dte-issues', [FiscalSyncController::class, 'prepareDteIssue']);
                Route::post('invalidations', [FiscalSyncController::class, 'prepareInvalidation']);
                Route::post('operations/{operation}/attach', [FiscalSyncController::class, 'attach']);
                Route::post('operations/{operation}/complete', [FiscalSyncController::class, 'complete']);
            });
        Route::get('platform/tenants/{tenant}/commercial/dashboard', CommercialDashboardController::class)
            ->middleware('tenant.app:facturacion');
        Route::prefix('platform/tenants/{tenant}/follow-up-notes')->group(function (): void {
            Route::get('/', [FollowUpNoteController::class, 'index']);
            Route::post('/', [FollowUpNoteController::class, 'store']);
            Route::put('{followUpNote}', [FollowUpNoteController::class, 'update']);
            Route::post('{followUpNote}/resolve', [FollowUpNoteController::class, 'resolve']);
            Route::post('{followUpNote}/discard', [FollowUpNoteController::class, 'discard']);
        });
        Route::prefix('platform/tenants/{tenant}/cash')
            ->middleware('tenant.app:facturacion')
            ->group(function (): void {
                Route::get('/', [CashRegisterController::class, 'overview']);
                Route::get('settings', [CashSettingsController::class, 'index']);
                Route::post('settings', [CashSettingsController::class, 'upsert']);
                Route::put('settings/{cashRegister}', [CashSettingsController::class, 'update']);
                Route::post('sessions', [CashRegisterController::class, 'open']);
                Route::post('sessions/{cashSession}/close', [CashRegisterController::class, 'close']);
                Route::post('movements', [CashRegisterController::class, 'storeMovement']);
                Route::post('movements/{cashMovement}/reverse', [CashRegisterController::class, 'reverse']);
                Route::post('expenses/{cashExpense}/reconcile', [CashRegisterController::class, 'reconcileExpense']);
                Route::get('sales-report', CommercialSalesReportController::class);
                Route::post('sales/{inventorySale}/payments', [CommercialSalesReportController::class, 'collect']);
            });
        Route::prefix('platform/tenants/{tenant}/workshop')
            ->middleware('tenant.app:taller')
            ->group(function (): void {
                Route::get('ticket-settings', [WorkshopTicketSettingsController::class, 'show']);
                Route::patch('ticket-settings', [WorkshopTicketSettingsController::class, 'update']);
                Route::get('dashboard', [WorkshopOrderController::class, 'dashboard']);
                Route::get('orders', [WorkshopOrderController::class, 'index']);
                Route::get('receptions/{reception}', [WorkshopOrderController::class, 'showReception']);
                Route::get('orders/{order}', [WorkshopOrderController::class, 'show']);
                Route::post('orders', [WorkshopOrderController::class, 'store']);
                Route::patch('orders/{order}', [WorkshopOrderController::class, 'update']);
                Route::post('orders/{order}/settlement', [WorkshopOrderController::class, 'settle']);
                Route::post('orders/{order}/payments', [WorkshopOrderController::class, 'recordPayment']);
                Route::post('orders/{order}/invoice-link', [WorkshopOrderController::class, 'linkInvoice']);
                Route::get('orders/{order}/photos', [WorkshopOrderController::class, 'photos']);
                Route::get('orders/{order}/photos/{photo}', [WorkshopOrderController::class, 'photo']);
                Route::post('orders/{order}/photo-session', [WorkshopOrderController::class, 'photoSession']);
                Route::post('orders/{order}/device-access', [WorkshopOrderController::class, 'deviceAccess']);
            });
        Route::post('platform/tenants/{tenant}/catalog/items', [CatalogItemController::class, 'store']);
        Route::patch('platform/tenants/{tenant}/catalog/items/{item}', [CatalogItemController::class, 'update']);
        Route::delete('platform/tenants/{tenant}/catalog/items/{item}', [CatalogItemController::class, 'destroy']);
        Route::get('platform/tenants/{tenant}/inventory/suppliers', [InventorySupplierController::class, 'index']);
        Route::post('platform/tenants/{tenant}/inventory/suppliers', [InventorySupplierController::class, 'store']);
        Route::patch('platform/tenants/{tenant}/inventory/suppliers/{supplier}', [InventorySupplierController::class, 'update']);
        Route::get('platform/tenants/{tenant}/inventory/purchases', [InventoryPurchaseController::class, 'index']);
        Route::post('platform/tenants/{tenant}/inventory/purchases', [InventoryPurchaseController::class, 'store']);
        Route::post('platform/tenants/{tenant}/inventory/purchases/import-dte-json', [InventoryPurchaseController::class, 'importDteJson']);
        Route::get('platform/tenants/{tenant}/inventory/purchases/{purchase}', [InventoryPurchaseController::class, 'show']);
        Route::get('platform/tenants/{tenant}/inventory/lots', [InventoryLotController::class, 'index']);
        Route::get('platform/tenants/{tenant}/inventory/movements', [InventoryMovementController::class, 'index']);
        Route::post('platform/tenants/{tenant}/inventory/adjustments', [InventoryMovementController::class, 'adjust']);
        Route::post('platform/tenants/{tenant}/inventory/sales', [InventorySaleController::class, 'store']);
        Route::get('platform/tenants/{tenant}/inventory/sales/fulfillment-by-source', [InventorySaleController::class, 'fulfillmentBySource']);
        Route::post('platform/tenants/{tenant}/inventory/sales/supersede-by-source', [InventorySaleController::class, 'supersedeBySource']);
        Route::post('platform/tenants/{tenant}/inventory/sales/reverse-by-source', [InventorySaleController::class, 'reverseBySource']);
        Route::post('platform/tenants/{tenant}/inventory/counts', [InventoryCountController::class, 'store']);
        Route::post('platform/tenants/{tenant}/inventory/transfers', [InventoryTransferController::class, 'store']);
        Route::get('platform/tenants/{tenant}/inventory/reports/sales', [InventoryReportController::class, 'sales']);
        Route::get('platform/tenants/{tenant}/inventory/reports/kardex', [InventoryReportController::class, 'kardex']);
        Route::get('platform/tenants/{tenant}/inventory/reports/margin', [InventoryReportController::class, 'margin']);
        Route::get('platform/tenants/{tenant}/inventory/reports/summary', [InventoryReportController::class, 'summary']);
        Route::get('platform/tenants/{tenant}/inventory/reports/stock-alerts', [InventoryReportController::class, 'stockAlerts']);
        Route::get('platform/tenants/{tenant}/inventory/reports/purchase-annex', [InventoryReportController::class, 'purchaseAnnex']);
        Route::get('platform/tenants/{tenant}/inventory/reports/purchase-annex/official', [InventoryReportController::class, 'purchaseAnnexOfficial']);
        Route::get('platform/tenants/{tenant}/inventory/reports/purchase-annex/csv', [InventoryReportController::class, 'purchaseAnnexCsv']);
        Route::get('platform/tenants/{tenant}/inventory/reports/count-sheet/pdf', [InventoryReportController::class, 'countSheetPdf']);
        Route::get('platform/tenants/{tenant}/inventory/reports/{report}/pdf', [InventoryReportController::class, 'pdf']);
        Route::post('platform/tenants/{tenant}/inventory/reservations', [InventoryReservationController::class, 'store']);
        Route::post('platform/tenants/{tenant}/inventory/reservations/{reservation}/confirm', [InventoryReservationController::class, 'confirm']);
        Route::post('platform/tenants/{tenant}/inventory/reservations/{reservation}/release', [InventoryReservationController::class, 'release']);
        Route::post('platform/tenants/{tenant}/inventory/reservations/{reservation}/reverse', [InventoryReservationController::class, 'reverse']);
        Route::get('platform/tenants/{tenant}/subscription', [SubscriptionController::class, 'showForTenant']);
        Route::get('platform/tenants/by-core-empresa/{coreEmpresaId}/subscription', [SubscriptionController::class, 'showForTenantByCoreEmpresa']);
        Route::get('platform/tenants/{tenant}/fiscal-scope', [TenantFiscalAssignmentController::class, 'scope']);
        Route::post('platform/tenants/{tenant}/users', [TenantUserController::class, 'store']);
        Route::post('platform/tenants/{tenant}/invitations', [TenantUserController::class, 'invite']);
        Route::post('platform/invitations/{token}/accept', [TenantInvitationController::class, 'accept']);
        Route::post('platform/invitations/{invitation}/resend', [TenantInvitationController::class, 'resend']);
        Route::get('platform/invitations/{invitation}/delivery', [TenantInvitationController::class, 'delivery']);
        Route::patch('platform/memberships/{membership}/role', [TenantMembershipController::class, 'updateRole']);
        Route::post('platform/memberships/{membership}/temporary-password', [TenantMembershipController::class, 'resetTemporaryPassword']);
        Route::put('platform/memberships/{membership}/fiscal-assignments', [TenantFiscalAssignmentController::class, 'store']);
        Route::patch('platform/memberships/{membership}/suspend', [TenantMembershipController::class, 'suspend']);
        Route::patch('platform/memberships/{membership}/reactivate', [TenantMembershipController::class, 'reactivate']);
        Route::delete('platform/memberships/{membership}', [TenantMembershipController::class, 'destroy']);
        Route::any('admin/core/{path?}', CoreProxyController::class)
            ->where('path', '.*');
        Route::any('admin/notifications/{path?}', NotificationProxyController::class)
            ->where('path', '.*');
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy']);
    });
});
