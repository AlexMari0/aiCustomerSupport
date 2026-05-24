<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Ticket;
use App\Support\OrganizationRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends ApiController
{
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $query = Customer::query()
            ->where('organization_id', $organization->id)
            ->withCount('tickets');

        $this->scopeAgentCustomers($query, $request, $organization);

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $searchTerm = trim($search);
            $query->where(function (Builder $builder) use ($searchTerm): void {
                $builder->where('name', 'like', "%{$searchTerm}%")
                    ->orWhere('email', 'like', "%{$searchTerm}%")
                    ->orWhere('phone', 'like', "%{$searchTerm}%");
            });
        }

        $sourceChannel = $request->query('source_channel');
        if (is_string($sourceChannel) && trim($sourceChannel) !== '') {
            $query->where('source_channel', trim($sourceChannel));
        }

        $tag = $request->query('tag');
        if (is_string($tag) && trim($tag) !== '') {
            $query->whereJsonContains('tags', trim($tag));
        }

        $customers = $query->orderBy('name')
            ->get()
            ->map(function (Customer $customer) {
                return [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'source_channel' => $customer->source_channel,
                    'tags' => $customer->tags ?? [],
                    'last_contacted_at' => $customer->last_contacted_at,
                    'tickets_count' => $customer->tickets_count,
                ];
            })
            ->values();

        return $this->success($customers, 'Customers retrieved.');
    }

    public function show(Request $request, Organization $organization, Customer $customer): JsonResponse
    {
        $scopedCustomer = $this->resolveScopedCustomer($organization, $customer);
        $this->assertAgentCustomerAccess($request, $organization, $scopedCustomer);

        $ticketHistory = Ticket::query()
            ->where('organization_id', $organization->id)
            ->where('customer_id', $scopedCustomer->id)
            ->with(['assignee:id,name,email'])
            ->latest('updated_at')
            ->get()
            ->map(function (Ticket $ticket) {
                return [
                    'id' => $ticket->id,
                    'subject' => $ticket->subject,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'category' => $ticket->category,
                    'assignee' => $ticket->assignee?->only(['id', 'name', 'email']),
                    'created_at' => $ticket->created_at,
                    'updated_at' => $ticket->updated_at,
                ];
            })
            ->values();

        return $this->success([
            'id' => $scopedCustomer->id,
            'name' => $scopedCustomer->name,
            'email' => $scopedCustomer->email,
            'phone' => $scopedCustomer->phone,
            'source_channel' => $scopedCustomer->source_channel,
            'tags' => $scopedCustomer->tags ?? [],
            'last_contacted_at' => $scopedCustomer->last_contacted_at,
            'ticket_history' => $ticketHistory,
        ], 'Customer detail retrieved.');
    }

    public function update(
        UpdateCustomerRequest $request,
        Organization $organization,
        Customer $customer
    ): JsonResponse {
        $scopedCustomer = $this->resolveScopedCustomer($organization, $customer);

        $updateData = [];
        foreach (['name', 'email', 'phone', 'source_channel', 'tags'] as $field) {
            if ($request->has($field)) {
                $updateData[$field] = $request->input($field);
            }
        }

        if ($updateData !== []) {
            $scopedCustomer->update($updateData);
        }

        return $this->success([
            'id' => $scopedCustomer->id,
            'name' => $scopedCustomer->name,
            'email' => $scopedCustomer->email,
            'phone' => $scopedCustomer->phone,
            'source_channel' => $scopedCustomer->source_channel,
            'tags' => $scopedCustomer->tags ?? [],
            'last_contacted_at' => $scopedCustomer->last_contacted_at,
        ], 'Customer updated successfully.');
    }

    private function resolveScopedCustomer(Organization $organization, Customer $customer): Customer
    {
        if ((int) $customer->organization_id !== (int) $organization->id) {
            abort(response()->json([
                'success' => false,
                'message' => 'Customer not found in this organization.',
                'errors' => [],
            ], JsonResponse::HTTP_NOT_FOUND));
        }

        return $customer;
    }

    private function assertAgentCustomerAccess(Request $request, Organization $organization, Customer $customer): void
    {
        if ($request->user()->organizationRole($organization->id) !== OrganizationRoles::AGENT) {
            return;
        }

        $hasAssignedTicket = Ticket::query()
            ->where('organization_id', $organization->id)
            ->where('customer_id', $customer->id)
            ->where('assigned_to', $request->user()->id)
            ->exists();

        if (! $hasAssignedTicket) {
            abort(response()->json([
                'success' => false,
                'message' => 'Agents can only access customers from assigned tickets.',
                'errors' => [],
            ], JsonResponse::HTTP_FORBIDDEN));
        }
    }

    private function scopeAgentCustomers(Builder $query, Request $request, Organization $organization): void
    {
        if ($request->user()->organizationRole($organization->id) !== OrganizationRoles::AGENT) {
            return;
        }

        $query->whereHas('tickets', function (Builder $ticketQuery) use ($request, $organization): void {
            $ticketQuery->where('organization_id', $organization->id)
                ->where('assigned_to', $request->user()->id);
        });
    }
}
