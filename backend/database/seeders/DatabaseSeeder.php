<?php

namespace Database\Seeders;

use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeBaseCategory;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationRoles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create or retrieve the test user
        $user = User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
            ]
        );

        // 2. Create the demo organization if none exists
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'demo-workspace'],
            [
                'name' => 'Demo Workspace',
                'join_code' => 'DEMO123456',
                'webhook_token' => 'demo_webhook_token_1234567890',
                'owner_user_id' => $user->id,
            ]
        );

        // 3. Associate the test user with the organization as Owner
        $organization->users()->syncWithoutDetaching([
            $user->id => ['role' => OrganizationRoles::OWNER],
        ]);

        // 4. Seed standard categories
        $categoriesData = [
            [
                'name' => 'Policies',
                'slug' => 'policies',
                'description' => 'Business operations, refund policies, and terms of service.',
            ],
            [
                'name' => 'Shipping',
                'slug' => 'shipping',
                'description' => 'Delivery estimates, address changes, and tracking support.',
            ],
            [
                'name' => 'Technical Support',
                'slug' => 'technical-support',
                'description' => 'Troubleshooting account registration, failed payments, and security.',
            ],
        ];

        $categories = [];
        foreach ($categoriesData as $cat) {
            $categories[$cat['slug']] = KnowledgeBaseCategory::query()->firstOrCreate(
                [
                    'organization_id' => $organization->id,
                    'slug' => $cat['slug'],
                ],
                [
                    'name' => $cat['name'],
                    'description' => $cat['description'],
                    'created_by' => $user->id,
                ]
            );
        }

        // 5. Seed the 6 requested articles
        $articlesData = [
            [
                'category_slug' => 'policies',
                'title' => 'Refund Policy',
                'slug' => 'refund-policy',
                'content' => "Our refund policy is simple and customer-first:\n\n1. **30-Day Window**: You can request a full refund within 30 days of purchase.\n2. **Item Condition**: Items must be unused, in their original packaging, and in resaleable condition.\n3. **Exclusions**: Digital gift cards and final sale items are non-refundable.\n4. **Process**: To initiate a refund, please contact support with your order number. Once approved, the refund will be credited to your original payment method within 5-7 business days.",
            ],
            [
                'category_slug' => 'shipping',
                'title' => 'Shipping Information',
                'slug' => 'shipping-information',
                'content' => "Here is what you need to know about our shipping practices:\n\n* **Standard Shipping**: Takes 3-5 business days domestically, and is free for orders over $50.\n* **Express Shipping**: Takes 1-2 business days, flat rate of $15.\n* **International Shipping**: Currently shipping to Canada, UK, and EU. Delivery takes 7-14 business days.\n* **Tracking**: You will receive a tracking link via email as soon as your package leaves our warehouse.",
            ],
            [
                'category_slug' => 'technical-support',
                'title' => 'Payment Failed',
                'slug' => 'payment-failed',
                'content' => "If your checkout payment has failed, please check the following common causes:\n\n1. **Billing Details**: Ensure the billing address and ZIP code match exactly with the card issuer records.\n2. **Card Expiration & CVV**: Double-check the expiration date and security code on the back of your card.\n3. **Daily Limit & Authorization**: Many banks flag sudden online transactions. You may need to call your bank to authorize the purchase.\n4. **Alternative Methods**: If issues persist, try checking out using Apple Pay, Google Pay, or a different card.",
            ],
            [
                'category_slug' => 'shipping',
                'title' => 'How to Change Order Address',
                'slug' => 'how-to-change-order-address',
                'content' => "If you made a typo or need to change your delivery address, speed is essential:\n\n1. **Pre-Shipment Status**: We can only change your shipping address if the order status is still marked as 'processing' or 'pending shipment'.\n2. **How to Request**: Please reply directly to your order confirmation email or open a support ticket immediately with the subject line 'URGENT: Address Change for Order #XXXX'.\n3. **Provide Details**: State your full name, order number, and the complete new shipping address.\n4. **Post-Shipment**: If the order has already shipped, we cannot reroute the package. You will need to contact the carrier (FedEx/UPS/DHL) with your tracking number to request a delivery redirect.",
            ],
            [
                'category_slug' => 'policies',
                'title' => 'Product Return Rules',
                'slug' => 'product-return-rules',
                'content' => "Please review our product return guidelines:\n\n* **Return Window**: Returns must be initiated within 30 days of receiving your item.\n* **Return Shipping**: Return shipping is free of charge. We will provide a pre-paid mailing label.\n* **Condition Requirement**: Items must be clean, unused, and in original boxes with all original accessories included.\n* **Exchanges**: If you wish to exchange for a different size/color, please return the item first for a refund, then place a new order on our storefront.",
            ],
            [
                'category_slug' => 'technical-support',
                'title' => 'Account Verification Issue',
                'slug' => 'account-verification-issue',
                'content' => "If you are having trouble verifying your account or haven't received your OTP/verification link email:\n\n1. **Check Spam Folder**: The confirmation email is automated and sometimes ends up in your Spam, Junk, or Promotions folder.\n2. **Wait 5 Minutes**: Depending on network congestion, the email delivery might be slightly delayed.\n3. **Resend Code**: Navigate back to the verification page and click 'Resend Verification Code'.\n4. **Safe Sender List**: Add 'no-reply@yourcompany.com' to your contact list or safe sender list to prevent it from being blocked.\n5. **Contact Support**: If you still do not receive it, please contact support with your registered email, and an admin can verify your account manually.",
            ],
        ];

        foreach ($articlesData as $art) {
            $cat = $categories[$art['category_slug']];

            KnowledgeBaseArticle::query()->firstOrCreate(
                [
                    'organization_id' => $organization->id,
                    'slug' => $art['slug'],
                ],
                [
                    'category_id' => $cat->id,
                    'title' => $art['title'],
                    'content' => $art['content'],
                    'status' => 'published',
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]
            );
        }

        // 6. Seed Customers
        $customerAlice = \App\Models\Customer::query()->firstOrCreate(
            ['email' => 'alice@merchant.com', 'organization_id' => $organization->id],
            [
                'name' => 'Alice Merchant',
                'phone' => '+15550192',
                'source_channel' => 'web',
                'tags' => ['VIP', 'New Customer'],
            ]
        );

        $customerBob = \App\Models\Customer::query()->firstOrCreate(
            ['email' => 'bob@example.com', 'organization_id' => $organization->id],
            [
                'name' => 'Bob Smith',
                'phone' => '+15550481',
                'source_channel' => 'whatsapp',
                'tags' => ['Repeat Issue'],
            ]
        );

        // 7. Seed Tickets & Messages
        $ticket1 = \App\Models\Ticket::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'customer_id' => $customerAlice->id,
                'subject' => 'Urgent: Need a refund for order #98721',
            ],
            [
                'created_by' => $user->id,
                'status' => \App\Support\TicketStatuses::OPEN,
                'priority' => \App\Support\TicketPriorities::HIGH,
                'category' => 'billing',
                'source_channel' => 'web',
            ]
        );

        \App\Models\TicketMessage::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'ticket_id' => $ticket1->id,
                'body' => 'Hi there! I bought the standard package last week (Order #98721), but it turns out we need the enterprise features instead. I would like to request a refund so that we can repurchase the correct license. Can you please help process this?',
            ],
            [
                'sender_type' => \App\Support\TicketMessageSenderTypes::CUSTOMER,
            ]
        );

        \App\Jobs\ClassifyTicketJob::dispatchSync($ticket1);

        $ticket2 = \App\Models\Ticket::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'customer_id' => $customerBob->id,
                'subject' => 'Where is my package? Standard shipping inquiry',
            ],
            [
                'created_by' => $user->id,
                'status' => \App\Support\TicketStatuses::OPEN,
                'priority' => \App\Support\TicketPriorities::MEDIUM,
                'category' => 'shipping',
                'source_channel' => 'whatsapp',
            ]
        );

        \App\Models\TicketMessage::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'ticket_id' => $ticket2->id,
                'body' => "Hello, I placed order #10892 four days ago and chose standard shipping, but I haven't received a tracking link yet. Could you check if it has shipped and when it will arrive?",
            ],
            [
                'sender_type' => \App\Support\TicketMessageSenderTypes::CUSTOMER,
            ]
        );

        \App\Jobs\ClassifyTicketJob::dispatchSync($ticket2);

        $ticket3 = \App\Models\Ticket::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'customer_id' => $customerBob->id,
                'subject' => 'Payment failed during checkout with card',
            ],
            [
                'created_by' => $user->id,
                'status' => \App\Support\TicketStatuses::OPEN,
                'priority' => \App\Support\TicketPriorities::LOW,
                'category' => 'technical_issue',
                'source_channel' => 'web',
            ]
        );

        \App\Models\TicketMessage::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'ticket_id' => $ticket3->id,
                'body' => 'Hi, I tried to purchase the subscription yesterday but my payment was declined. I tried multiple times but it says checkout error. Can you help me?',
            ],
            [
                'sender_type' => \App\Support\TicketMessageSenderTypes::CUSTOMER,
            ]
        );

        \App\Jobs\ClassifyTicketJob::dispatchSync($ticket3);

        // 8. Seed Automation Rules
        $escalateRule = \App\Models\AutomationRule::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'name' => 'Escalate Urgent & Angry Tickets',
            ],
            [
                'trigger_type' => 'ticket_created',
                'is_active' => true,
                'created_by' => $user->id,
            ]
        );

        if ($escalateRule->wasRecentlyCreated || !$escalateRule->conditions()->exists()) {
            $escalateRule->conditions()->create([
                'field' => 'sentiment',
                'operator' => 'equals',
                'value' => 'angry',
            ]);

            $escalateRule->actions()->create([
                'action_type' => 'change_priority',
                'action_value' => 'urgent',
            ]);

            $escalateRule->actions()->create([
                'action_type' => 'add_internal_note',
                'action_value' => 'System: This ticket has been automatically escalated to URGENT due to angry sentiment detected.',
            ]);
        }

        $refundRule = \App\Models\AutomationRule::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'name' => 'Route Refunds to Owner',
            ],
            [
                'trigger_type' => 'ticket_created',
                'is_active' => true,
                'created_by' => $user->id,
            ]
        );

        if ($refundRule->wasRecentlyCreated || !$refundRule->conditions()->exists()) {
            $refundRule->conditions()->create([
                'field' => 'category',
                'operator' => 'equals',
                'value' => 'refund',
            ]);

            $refundRule->actions()->create([
                'action_type' => 'assign_to_agent',
                'action_value' => (string) $user->id,
            ]);

            $refundRule->actions()->create([
                'action_type' => 'add_internal_note',
                'action_value' => 'System: Refund ticket automatically routed to workspace Owner.',
            ]);
        }

        // 9. Seed a sample run for Ticket #1 (Refund)
        \App\Models\AutomationRun::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'automation_rule_id' => $refundRule->id,
                'ticket_id' => $ticket1->id,
            ],
            [
                'status' => 'success',
                'logs' => [
                    'message' => 'Executed Route Refunds to Owner rule successfully.',
                    'actions_executed' => [
                        'assign_to_agent' => $user->id,
                        'add_internal_note' => 'System: Refund ticket automatically routed to workspace Owner.',
                    ]
                ]
            ]
        );

        // 10. Seed beautiful, realistic Audit Logs
        $auditLogsData = [
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'event' => 'ticket_created',
                'target_type' => 'Ticket',
                'target_id' => $ticket1->id,
                'metadata' => [
                    'subject' => $ticket1->subject,
                    'priority' => $ticket1->priority,
                    'category' => $ticket1->category,
                    'source_channel' => $ticket1->source_channel,
                ],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
                'created_at' => now()->subHours(5),
            ],
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'event' => 'ticket_created',
                'target_type' => 'Ticket',
                'target_id' => $ticket2->id,
                'metadata' => [
                    'subject' => $ticket2->subject,
                    'priority' => $ticket2->priority,
                    'category' => $ticket2->category,
                    'source_channel' => $ticket2->source_channel,
                ],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
                'created_at' => now()->subHours(4),
            ],
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'event' => 'workflow_executed',
                'target_type' => 'Ticket',
                'target_id' => $ticket1->id,
                'metadata' => [
                    'rule_id' => $refundRule->id,
                    'rule_name' => $refundRule->name,
                    'status' => 'success',
                    'actions_executed' => [
                        [
                            'action_type' => 'assign_to_agent',
                            'action_value' => (string) $user->id,
                            'result' => 'Ticket #1 assigned to Agent ID ' . $user->id,
                        ],
                        [
                            'action_type' => 'add_internal_note',
                            'action_value' => 'System: Refund ticket automatically routed to workspace Owner.',
                            'result' => 'Note added to Ticket #1',
                        ]
                    ]
                ],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'System Engine',
                'created_at' => now()->subHours(4)->addMinutes(5),
            ],
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'event' => 'knowledge_article_updated',
                'target_type' => 'KnowledgeBaseArticle',
                'target_id' => 1,
                'metadata' => [
                    'action' => 'created',
                    'title' => 'Refund Policy',
                    'status' => 'published',
                ],
                'ip_address' => '192.168.1.5',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
                'created_at' => now()->subHours(8),
            ],
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'event' => 'knowledge_article_updated',
                'target_type' => 'KnowledgeBaseArticle',
                'target_id' => 2,
                'metadata' => [
                    'action' => 'created',
                    'title' => 'Shipping Information',
                    'status' => 'published',
                ],
                'ip_address' => '192.168.1.5',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
                'created_at' => now()->subHours(7),
            ]
        ];

        foreach ($auditLogsData as $logData) {
            \App\Models\AuditLog::query()->firstOrCreate(
                [
                    'organization_id' => $logData['organization_id'],
                    'event' => $logData['event'],
                    'target_type' => $logData['target_type'],
                    'target_id' => $logData['target_id'],
                    'created_at' => $logData['created_at'],
                ],
                [
                    'user_id' => $logData['user_id'],
                    'metadata' => $logData['metadata'],
                    'ip_address' => $logData['ip_address'],
                    'user_agent' => $logData['user_agent'],
                ]
            );
        }
    }
}
