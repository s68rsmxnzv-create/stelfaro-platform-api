<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['slug', 'name', 'status', 'primary_app_id', 'metadata'])]
class Tenant extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function primaryApp(): BelongsTo
    {
        return $this->belongsTo(PlatformApp::class, 'primary_app_id');
    }

    public function appAccesses(): HasMany
    {
        return $this->hasMany(TenantAppAccess::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(UserTenantMembership::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(UserInvitation::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(TenantSubscription::class);
    }

    public function accountantContacts(): HasMany
    {
        return $this->hasMany(AccountantContact::class);
    }

    public function wompiPaymentEvents(): HasMany
    {
        return $this->hasMany(WompiPaymentEvent::class);
    }

    public function catalogCategories(): HasMany
    {
        return $this->hasMany(CatalogCategory::class);
    }

    public function catalogItems(): HasMany
    {
        return $this->hasMany(CatalogItem::class);
    }

    public function inventorySuppliers(): HasMany
    {
        return $this->hasMany(InventorySupplier::class);
    }

    public function inventoryPurchases(): HasMany
    {
        return $this->hasMany(InventoryPurchase::class);
    }

    public function cashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class);
    }

    public function cashSessions(): HasMany
    {
        return $this->hasMany(CashSession::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function cashExpenses(): HasMany
    {
        return $this->hasMany(CashExpense::class);
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function receivableAccounts(): HasMany
    {
        return $this->hasMany(ReceivableAccount::class);
    }

    public function inventoryReservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function workshopOrders(): HasMany
    {
        return $this->hasMany(WorkshopOrder::class);
    }

    public function internalNotifications(): HasMany
    {
        return $this->hasMany(InternalNotification::class);
    }

    public function legalAcceptances(): HasMany
    {
        return $this->hasMany(LegalAcceptance::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(TenantRequest::class);
    }
}
