import { Head } from '@inertiajs/react';
import CreditCardForm from '@/components/credit-cards/credit-card-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index } from '@/routes/credit-cards';
import type {
    CreditCard,
    CreditCardReferenceOption,
    PaymentMethodOption,
} from '@/types';

type Props = {
    card: CreditCard;
    memberOptions: CreditCardReferenceOption[];
    accountOptions: CreditCardReferenceOption[];
    invoicePaymentMethods: PaymentMethodOption[];
};

export default function CreditCardsEdit({ card, ...options }: Props) {
    return (
        <>
            <Head title={`Editar ${card.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Editar cartão
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Atualize os dados de {card.name} final {card.last_four}.
                    </p>
                </div>

                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Dados do cartão</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <CreditCardForm card={card} {...options} />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

CreditCardsEdit.layout = {
    breadcrumbs: [
        { title: 'Cartões', href: index() },
        { title: 'Editar cartão', href: index() },
    ],
};
