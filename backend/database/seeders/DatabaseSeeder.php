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
    }
}
