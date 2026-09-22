import { SearchX } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';

type Props = {
    title?: string;
    description?: string;
};

export function ListingEmpty({
    title = 'Nenhum resultado encontrado',
    description = 'Ajuste a busca ou os filtros para ver outros registros.',
}: Props) {
    return (
        <Card className="border-dashed">
            <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                <div className="bg-muted rounded-full p-3">
                    <SearchX className="text-muted-foreground size-6" />
                </div>
                <div className="space-y-1">
                    <h2 className="font-medium">{title}</h2>
                    <p className="text-muted-foreground max-w-md text-sm">
                        {description}
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}
