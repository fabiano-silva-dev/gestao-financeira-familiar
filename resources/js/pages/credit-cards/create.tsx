import { Head } from '@inertiajs/react';
import CreditCardForm from '@/components/credit-cards/credit-card-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { create, index } from '@/routes/credit-cards';
import type { CreditCardReferenceOption, PaymentMethodOption } from '@/types';

type Props = {
    memberOptions: CreditCardReferenceOption[];
    accountOptions: CreditCardReferenceOption[];
    invoicePaymentMethods: PaymentMethodOption[];
};

export default function CreditCardsCreate(props: Props) {
    return (
        <>
            <Head title="Novo cartão" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Novo cartão
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Cadastre o ciclo e a forma padrão de pagamento da
                        fatura.
                    </p>
                </div>

                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Dados do cartão</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <CreditCardForm {...props} />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

CreditCardsCreate.layout = {
    breadcrumbs: [
        { title: 'Cartões', href: index() },
        { title: 'Novo cartão', href: create() },
    ],
};
