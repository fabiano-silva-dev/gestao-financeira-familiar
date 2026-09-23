<?php

namespace App\Enums;

enum ClassificationRuleAutomationLevel: string
{
    case ClassifyOnly = 'classify_only';
    case ReconcileExisting = 'reconcile_existing';
    case CreateAndReconcile = 'create_and_reconcile';

    public function label(): string
    {
        return match ($this) {
            self::ClassifyOnly => 'Somente classificar',
            self::ReconcileExisting => 'Conciliar automaticamente',
            self::CreateAndReconcile => 'Criar e conciliar automaticamente',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::ClassifyOnly => 'Aplica beneficiário e categoria sugeridos, mas mantém o movimento pendente para confirmação.',
            self::ReconcileExisting => 'Classifica e concilia somente quando encontra um lançamento existente com correspondência segura. Nunca cria um novo lançamento.',
            self::CreateAndReconcile => 'Classifica, procura primeiro um lançamento existente e, se não houver candidato relevante nem ambiguidade, cria pelo serviço do domínio e concilia.',
        };
    }

    public function canCreate(): bool
    {
        return $this === self::CreateAndReconcile;
    }

    public function canReconcile(): bool
    {
        return $this !== self::ClassifyOnly;
    }

    /**
     * @return list<array{value: string, label: string, help: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $level): array => [
                'value' => $level->value,
                'label' => $level->label(),
                'help' => $level->help(),
            ],
            self::cases(),
        );
    }
}
