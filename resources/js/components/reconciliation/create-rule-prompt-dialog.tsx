import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { classificationRuleCreateQuery } from '@/lib/classification-rule';
import { create } from '@/routes/classification-rules';
import type { ClassificationRulePrompt } from '@/types';

type Props = {
    prompt: ClassificationRulePrompt | null;
    onClose: () => void;
};

export function CreateRulePromptDialog({ prompt, onClose }: Props) {
    return (
        <Dialog
            open={prompt !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Criar uma regra?</DialogTitle>
                    <DialogDescription>
                        Este movimento foi concluído. Uma regra classifica
                        automaticamente descrições parecidas na próxima
                        importação, sem alterar o extrato original.
                    </DialogDescription>
                </DialogHeader>
                {prompt && (
                    <p className="bg-muted rounded-lg px-3 py-2 text-sm">
                        {prompt.description}
                    </p>
                )}
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Agora não
                    </Button>
                    {prompt && (
                        <Button asChild>
                            <Link
                                href={`${create.url()}${classificationRuleCreateQuery(prompt)}`}
                            >
                                Criar regra
                            </Link>
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
