<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE transaction_installments AS installment
            SET competence_month = corrected.competence_month,
                updated_at = CURRENT_TIMESTAMP
            FROM (
                SELECT
                    installment.id,
                    (
                        date_trunc('month', transaction.competence_date)
                        + (
                            (
                                installment.installment_number - anchor.anchor_number
                            ) * INTERVAL '1 month'
                        )
                    )::date AS competence_month
                FROM transaction_installments AS installment
                JOIN financial_transactions AS transaction
                    ON transaction.id = installment.financial_transaction_id
                JOIN (
                    SELECT
                        financial_transaction_id,
                        MIN(installment_number) AS anchor_number
                    FROM transaction_installments
                    GROUP BY financial_transaction_id
                ) AS anchor
                    ON anchor.financial_transaction_id = installment.financial_transaction_id
                WHERE transaction.competence_date IS NOT NULL
            ) AS corrected
            WHERE installment.id = corrected.id
              AND installment.competence_month IS DISTINCT FROM corrected.competence_month
        SQL);
    }

    public function down(): void
    {
        //
    }
};
