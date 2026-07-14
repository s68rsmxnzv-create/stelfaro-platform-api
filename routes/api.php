<?php

use App\Http\Controllers\Api\V1\Platform\GlobalUserController;
use App\Http\Controllers\Api\V1\Platform\CatalogCategoryController;
use App\Http\Controllers\Api\V1\Platform\CatalogItemController;
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
use App\Http\Controllers\Api\V1\Platform\TenantUserController;
use App\Http\Controllers\Api\V1\PlatformSessionController;
use App\Http\Controllers\Api\V1\WompiWebhookController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\PlatformAdmin\CoreProxyController;
use App\Http\Controllers\PlatformAdmin\CoreSessionController;
use App\Http\Controllers\PlatformAdmin\NotificationProxyController;
use App\Http\Controllers\PlatformAdmin\TenantAppOnboardingController;
use App\Http\Middleware\EnsurePasswordIsChanged;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', fn () => response()->json([
        'status' => 'ok',
        'service' => config('app.name', 'Stelfaro Platform API'),
        'version' => app()->version(),
        'timestamp' => now()->toISOString(),
    ]));

    Route::post('webhooks/wompi', WompiWebhookController::class);

    Route::middleware(['web', 'auth', 'verified', EnsurePasswordIsChanged::class])->group(function (): void {
        Route::get('me', PlatformSessionController::class);
        Route::patch('me/active-membership/{membership}', [TenantMembershipController::class, 'setActive']);
        Route::get('admin/platform/users', [GlobalUserController::class, 'index']);
        Route::get('admin/platform/audit-logs', [PlatformAuditLogController::class, 'index']);
        Route::get('admin/platform/subscriptions', [SubscriptionController::class, 'index']);
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
        Route::get('platform/tenants/{tenant}/audit-logs', [PlatformAuditLogController::class, 'tenant']);
        Route::get('platform/tenants/{tenant}/catalog/categories', [CatalogCategoryController::class, 'index']);
        Route::post('platform/tenants/{tenant}/catalog/categories', [CatalogCategoryController::class, 'store']);
        Route::patch('platform/tenants/{tenant}/catalog/categories/{category}', [CatalogCategoryController::class, 'update']);
        Route::delete('platform/tenants/{tenant}/catalog/categories/{category}', [CatalogCategoryController::class, 'destroy']);
        Route::get('platform/tenants/{tenant}/catalog/items', [CatalogItemController::class, 'index']);
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
        Route::post('platform/tenants/{tenant}/inventory/sales/reverse-by-source', [InventorySaleController::class, 'reverseBySource']);
        Route::post('platform/tenants/{tenant}/inventory/counts', [InventoryCountController::class, 'store']);
        Route::post('platform/tenants/{tenant}/inventory/transfers', [InventoryTransferController::class, 'store']);
        Route::get('platform/tenants/{tenant}/inventory/reports/sales', [InventoryReportController::class, 'sales']);
        Route::get('platform/tenants/{tenant}/inventory/reports/kardex', [InventoryReportController::class, 'kardex']);
        Route::get('platform/tenants/{tenant}/inventory/reports/margin', [InventoryReportController::class, 'margin']);
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
