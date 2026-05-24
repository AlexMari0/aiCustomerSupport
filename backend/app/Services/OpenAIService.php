<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OpenAIService
{
    protected ?string $apiKey;

    public function __construct()
    {
        $this->apiKey = env('OPENAI_API_KEY') ?: config('services.openai.key');
    }

    /**
     * Generate vector embedding (1536 dimensions) for the given text.
     * Uses text-embedding-3-small.
     *
     * @return array<int, float>
     */
    public function generateEmbedding(string $text): array
    {
        if (empty($this->apiKey)) {
            Log::info("OpenAI API key is missing. Generating deterministic mock embedding.");
            return $this->generateMockEmbedding($text);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->timeout(8)
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => 'text-embedding-3-small',
                'input' => $text,
            ]);

            if ($response->successful()) {
                return $response->json('data.0.embedding');
            }

            Log::warning("OpenAI embedding API failed: " . $response->body() . ". Using mock fallback.");
        } catch (\Exception $e) {
            Log::warning("OpenAI embedding exception: " . $e->getMessage() . ". Using mock fallback.");
        }

        return $this->generateMockEmbedding($text);
    }

    /**
     * Generate suggested reply based on ticket conversation context and retrieved KB articles.
     * Uses gpt-4o-mini.
     */
    public function generateSuggestedReply(string $conversationContext, string $articleContext): string
    {
        if (empty($this->apiKey)) {
            Log::info("OpenAI API key is missing. Generating keyword-matching fallback reply.");
            return $this->generateMockReply($conversationContext);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->timeout(12)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "You are an expert customer support agent. Ground your response strictly on the provided knowledge base articles. If the articles do not answer the user's question, state that politely, but try to be as helpful as possible based on standard business practices. Keep your response friendly, empathetic, concise, and professional.",
                    ],
                    [
                        'role' => 'user',
                        'content' => "Knowledge Base Articles Context:\n" . $articleContext . "\n\nConversation History:\n" . $conversationContext . "\n\nSuggested Reply:",
                    ],
                ],
                'temperature' => 0.4,
                'max_tokens' => 400,
            ]);

            if ($response->successful()) {
                return trim($response->json('choices.0.message.content'));
            }

            Log::warning("OpenAI chat completions API failed: " . $response->body() . ". Using mock fallback.");
        } catch (\Exception $e) {
            Log::warning("OpenAI chat completions exception: " . $e->getMessage() . ". Using mock fallback.");
        }

        return $this->generateMockReply($conversationContext);
    }

    /**
     * Classify ticket subject and first message context.
     * Returns category, sentiment, priority, confidence score.
     *
     * @return array{category: string, sentiment: string, priority: string, confidence: float}
     */
    public function classifyTicket(string $subject, string $firstMessage): array
    {
        if (empty($this->apiKey)) {
            Log::info("OpenAI API key is missing. Generating deterministic mock classification.");
            return $this->generateMockClassification($subject, $firstMessage);
        }

        try {
            $prompt = "Classify this customer support ticket based on the subject and the first customer message.\n\n" .
                "Subject: {$subject}\n" .
                "Message: {$firstMessage}\n\n" .
                "You MUST return a JSON object with the exact keys: 'category', 'sentiment', 'priority', 'confidence'.\n\n" .
                "Allowed Category values: 'billing', 'refund', 'technical_issue', 'shipping', 'product_question', 'account_issue'.\n" .
                "Allowed Sentiment values: 'neutral', 'frustrated', 'angry', 'satisfied'.\n" .
                "Allowed Priority values: 'low', 'medium', 'high', 'urgent'.\n" .
                "Confidence should be a float between 0.0 and 1.0.";

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->timeout(10)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "You are an AI support ticket classifier. Analyze the subject and customer message carefully and return a JSON object containing category, sentiment, priority, and confidence score.",
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.0,
            ]);

            if ($response->successful()) {
                $json = json_decode($response->json('choices.0.message.content'), true);
                if (is_array($json) && isset($json['category'], $json['sentiment'], $json['priority'], $json['confidence'])) {
                    return [
                        'category' => (string) $json['category'],
                        'sentiment' => (string) $json['sentiment'],
                        'priority' => (string) $json['priority'],
                        'confidence' => (float) $json['confidence'],
                    ];
                }
            }

            Log::warning("OpenAI classification failed: " . $response->body() . ". Using mock fallback.");
        } catch (\Exception $e) {
            Log::warning("OpenAI classification exception: " . $e->getMessage() . ". Using mock fallback.");
        }

        return $this->generateMockClassification($subject, $firstMessage);
    }

    /**
     * Generate structured mock ticket classification based on content keywords.
     *
     * @return array{category: string, sentiment: string, priority: string, confidence: float}
     */
    protected function generateMockClassification(string $subject, string $firstMessage): array
    {
        $content = Str::lower($subject . ' ' . $firstMessage);

        $category = 'product_question';
        $sentiment = 'neutral';
        $priority = 'medium';
        $confidence = 0.75;

        // Detect sentiment
        if (Str::contains($content, ['angry', 'furious', 'terrible', 'awful', 'hate', 'unacceptable', 'bad service'])) {
            $sentiment = 'angry';
            $priority = 'urgent';
            $confidence = 0.94;
        } elseif (Str::contains($content, ['frustrated', 'annoyed', 'disappointed', 'waiting', 'slow', 'broken', 'not working', 'failed'])) {
            $sentiment = 'frustrated';
            $priority = 'high';
            $confidence = 0.90;
        } elseif (Str::contains($content, ['happy', 'satisfied', 'great', 'thank you', 'thanks', 'perfect'])) {
            $sentiment = 'satisfied';
            $confidence = 0.88;
        }

        // Detect category & priority overrides
        if (Str::contains($content, ['refund', 'money back', 'chargeback'])) {
            $category = 'refund';
            $priority = $sentiment === 'angry' ? 'urgent' : 'high';
            $confidence = 0.96;
        } elseif (Str::contains($content, ['shipping', 'delivery', 'express', 'track', 'arrive', 'package'])) {
            $category = 'shipping';
            if ($sentiment === 'neutral') {
                $priority = 'medium';
            }
            $confidence = 0.92;
        } elseif (Str::contains($content, ['payment', 'failed', 'declined', 'checkout', 'card', 'billing', 'charge'])) {
            $category = 'billing';
            $priority = $sentiment === 'angry' ? 'urgent' : 'high';
            $confidence = 0.95;
        } elseif (Str::contains($content, ['password', 'account', 'login', 'register', 'verification', 'verify', 'otp'])) {
            $category = 'account_issue';
            $confidence = 0.89;
        } elseif (Str::contains($content, ['error', 'bug', 'broken', 'crash', 'not working', 'issue', 'technical'])) {
            $category = 'technical_issue';
            $confidence = 0.87;
        }

        return [
            'category' => $category,
            'sentiment' => $sentiment,
            'priority' => $priority,
            'confidence' => $confidence,
        ];
    }

    /**
     * Generate a deterministic mock float vector of 1536 elements.
     *
     * @return array<int, float>
     */
    protected function generateMockEmbedding(string $text): array
    {
        $hash = md5($text);
        $vector = [];
        // Populate 1536 float values based on hash to ensure deterministic behavior
        for ($i = 0; $i < 1536; $i++) {
            $charIndex = $i % 32;
            $hexChar = substr($hash, $charIndex, 1);
            $val = hexdec($hexChar) / 15.0; // Value between 0.0 and 1.0
            $vector[] = (float) ($val - 0.5); // Normalize to [-0.5, 0.5]
        }
        return $vector;
    }

    /**
     * Generate template suggested replies matching conversation keyword queries.
     */
    protected function generateMockReply(string $context): string
    {
        $lowerContext = Str::lower($context);

        if (Str::contains($lowerContext, ['refund', 'money back', 'chargeback'])) {
            return "Hello! Thank you for reaching out to us.\n\nAccording to our Refund Policy, we support a full refund within 30 days of purchase for unused items in their original packaging. Since your request falls within this 30-day window, I can gladly process this refund for you. Please confirm if you would like me to proceed!\n\nBest regards,\nSupport Team";
        }

        if (Str::contains($lowerContext, ['shipping', 'delivery', 'express', 'track', 'arrives'])) {
            return "Hello! I would be happy to help check your shipment.\n\nOur standard shipping takes 3-5 business days domestically, and is free for orders over $50. Express shipping takes 1-2 business days for a flat rate of $15. Once your package leaves our warehouse, a tracking link is automatically emailed to you. If you need me to check the status of a specific order, please share the order number!\n\nWarm regards,\nSupport Team";
        }

        if (Str::contains($lowerContext, ['payment failed', 'card declined', 'checkout error', 'decline'])) {
            return "Hello! I'm sorry to hear you experienced issues during checkout.\n\nWhen a payment fails, it is usually due to a slight typo in billing details (ZIP code/billing address) or security locks from your bank. Please check your CVV and expiration date or try alternative methods like Google Pay or Apple Pay. If billing details are correct, we recommend contacting your card provider to authorize the transaction.\n\nBest regards,\nSupport Team";
        }

        if (Str::contains($lowerContext, ['change address', 'delivery address', 'typo in address'])) {
            return "Hello! Thank you for letting us know immediately.\n\nWe can update your delivery address as long as the order is still marked as 'processing' or 'pending shipment'. Please reply with your order number and your complete new shipping address as soon as possible so I can update it before it leaves our warehouse.\n\nBest regards,\nSupport Team";
        }

        if (Str::contains($lowerContext, ['return rules', 'how to return', 'exchange'])) {
            return "Hello! We would be happy to assist you with your return.\n\nReturns must be initiated within 30 days of receiving your item. We provide free return shipping labels, and items must be clean and unused in their original boxes. For exchanges, we suggest returning the original item for a refund, and then placing a new order on our store. Please let us know if you'd like us to send a return label!\n\nWarmly,\nSupport Team";
        }

        if (Str::contains($lowerContext, ['verify', 'verification', 'otp', 'verification link', 'junk'])) {
            return "Hello! I understand you are having trouble verifying your account.\n\nPlease check your Junk, Spam, or Promotions folders as automatic confirmation emails can sometimes be rerouted. If you still do not see it, you can click 'Resend Verification Code' on the sign-in page. Let me know if the issue persists so I can manually verify your account details for you!\n\nBest regards,\nSupport Team";
        }

        return "Hello! Thank you for reaching out to us.\n\nI have received your inquiry and would be glad to assist you. To help me provide the most accurate support, could you please provide a few more details or clarify your request? I am here to help you get this resolved!\n\nBest regards,\nSupport Team";
    }
}
