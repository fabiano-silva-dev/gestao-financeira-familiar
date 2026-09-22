<?php

namespace App\Enums;

enum ClassificationRuleMatchType: string
{
    case Contains = 'contains';
    case ContainsAllWords = 'contains_all_words';
    case ContainsAnyWord = 'contains_any_word';
    case StartsWith = 'starts_with';
    case Equals = 'equals';

    public function label(): string
    {
        return match ($this) {
            self::Contains => 'Contém o trecho',
            self::ContainsAllWords => 'Contém todas as palavras',
            self::ContainsAnyWord => 'Contém qualquer palavra',
            self::StartsWith => 'Começa com',
            self::Equals => 'Igual à descrição',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Contains => 'A descrição precisa ter este texto, mesmo no meio. Serve para um nome completo ou parte dele, como FABIANO CARVALHO DA SILVA em PIX - FABIANO CARVALHO DA SILVA.',
            self::ContainsAllWords => 'Todas as palavras precisam aparecer, em qualquer ordem. Serve para Mercado Pago Fabiano em transferencia para conta Mercado Pago Fabiano.',
            self::ContainsAnyWord => 'Basta uma das palavras aparecer na descrição. Use com trechos bem específicos para não classificar movimentos demais.',
            self::StartsWith => 'A descrição precisa começar com este texto.',
            self::Equals => 'A descrição precisa ser igual a este texto, ignorando maiúsculas e acentos.',
        };
    }

    /**
     * @return list<array{value: string, label: string, help: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'help' => $type->help(),
            ],
            self::cases(),
        );
    }
}
