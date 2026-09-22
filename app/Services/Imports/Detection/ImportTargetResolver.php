<?php

namespace App\Services\Imports\Detection;

use App\Models\CreditCard;
use App\Models\FinancialAccount;
use App\Models\ImportSourceBinding;
use App\Models\Workspace;

final class ImportTargetResolver
{
    public function __construct(
        private readonly InstitutionMatcher $institutions,
    ) {}

    public function resolveAccount(
        Workspace $workspace,
        FinancialDocumentDetection $detection,
    ): ?FinancialAccount {
        $bound = $this->boundAccount($workspace, $detection);

        if ($bound instanceof FinancialAccount && $bound->is_active) {
            return $bound;
        }

        $accounts = $workspace->financialAccounts()
            ->where('is_active', true)
            ->get();

        if (
            in_array($detection->identifierType, ['account_id', 'account_number'], true)
            && $detection->identifierValue !== null
        ) {
            $identifier = $this->normalizeAccountIdentifier($detection->identifierValue);
            $identifierMatches = $accounts
                ->filter(function (FinancialAccount $account) use ($identifier, $detection): bool {
                    if ($account->account_number === null) {
                        return false;
                    }

                    if (
                        $this->normalizeAccountIdentifier($account->account_number)
                        !== $identifier
                    ) {
                        return false;
                    }

                    return $detection->institution === null
                        || $this->institutions->matches(
                            $detection->institution,
                            $account->institution,
                        );
                })
                ->values();

            if ($identifierMatches->count() === 1) {
                return $identifierMatches->first();
            }

            if ($identifierMatches->count() > 1) {
                return null;
            }
        }

        if ($detection->institution !== null) {
            $matches = $accounts
                ->filter(fn (FinancialAccount $account): bool => $this->institutions->matches(
                    $detection->institution,
                    $account->institution,
                ))
                ->values();

            return $matches->count() === 1 ? $matches->first() : null;
        }

        return $accounts->count() === 1 ? $accounts->first() : null;
    }

    public function resolveCard(
        Workspace $workspace,
        FinancialDocumentDetection $detection,
    ): ?CreditCard {
        $bound = $this->boundCard($workspace, $detection);

        if ($bound instanceof CreditCard && $bound->is_active) {
            return $bound;
        }

        $cards = $workspace->creditCards()
            ->where('is_active', true)
            ->get();

        if (
            $detection->identifierType === 'card_last_four'
            && $detection->identifierValue !== null
        ) {
            $matches = $cards
                ->filter(function (CreditCard $card) use ($detection): bool {
                    if ($card->last_four !== $detection->identifierValue) {
                        return false;
                    }

                    return $detection->institution === null
                        || $this->institutions->matches($detection->institution, $card->institution)
                        || $this->institutions->matches($detection->institution, $card->name);
                })
                ->values();

            return $matches->count() === 1 ? $matches->first() : null;
        }

        if ($detection->institution !== null) {
            $matches = $cards
                ->filter(fn (CreditCard $card): bool => $this->institutions->matches(
                    $detection->institution,
                    $card->institution,
                ) || $this->institutions->matches(
                    $detection->institution,
                    $card->name,
                ))
                ->values();

            return $matches->count() === 1 ? $matches->first() : null;
        }

        return $cards->count() === 1 ? $cards->first() : null;
    }

    public function learnAccount(
        Workspace $workspace,
        FinancialDocumentDetection $detection,
        FinancialAccount $account,
    ): void {
        if (! $this->canLearn($detection)) {
            return;
        }

        ImportSourceBinding::query()->updateOrCreate(
            $this->identity($workspace, $detection),
            [
                'financial_account_id' => $account->id,
                'credit_card_id' => null,
            ],
        );
    }

    public function learnCard(
        Workspace $workspace,
        FinancialDocumentDetection $detection,
        CreditCard $card,
    ): void {
        if (! $this->canLearn($detection)) {
            return;
        }

        ImportSourceBinding::query()->updateOrCreate(
            $this->identity($workspace, $detection),
            [
                'financial_account_id' => null,
                'credit_card_id' => $card->id,
            ],
        );
    }

    private function boundAccount(
        Workspace $workspace,
        FinancialDocumentDetection $detection,
    ): ?FinancialAccount {
        $binding = $this->findBinding($workspace, $detection);

        return $binding?->financialAccount;
    }

    private function boundCard(
        Workspace $workspace,
        FinancialDocumentDetection $detection,
    ): ?CreditCard {
        $binding = $this->findBinding($workspace, $detection);

        return $binding?->creditCard;
    }

    private function findBinding(
        Workspace $workspace,
        FinancialDocumentDetection $detection,
    ): ?ImportSourceBinding {
        if (! $this->canLearn($detection)) {
            return null;
        }

        return ImportSourceBinding::query()
            ->where($this->identity($workspace, $detection))
            ->first();
    }

    /** @return array<string, int|string> */
    private function identity(
        Workspace $workspace,
        FinancialDocumentDetection $detection,
    ): array {
        return [
            'workspace_id' => $workspace->id,
            'institution' => $detection->institution ?? 'unknown',
            'document_type' => $detection->documentType,
            'identifier_type' => (string) $detection->identifierType,
            'identifier_value' => (string) $detection->identifierValue,
        ];
    }

    private function normalizeAccountIdentifier(string $value): string
    {
        $normalized = preg_replace('/[^a-z0-9]/i', '', $value);

        return mb_strtolower($normalized ?? $value);
    }

    private function canLearn(FinancialDocumentDetection $detection): bool
    {
        return $detection->identifierType !== null
            && $detection->identifierValue !== null
            && trim($detection->identifierValue) !== '';
    }
}
