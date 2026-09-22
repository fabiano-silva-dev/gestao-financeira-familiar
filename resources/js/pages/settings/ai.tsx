import { Form, Head, router } from '@inertiajs/react';
import { useRef } from 'react';
import AiSettingsController from '@/actions/App/Http/Controllers/Settings/AiSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/ai-settings';

type LastTest = {
    provider: 'gemini' | 'groq';
    ok: boolean;
    message: string;
} | null;

type Props = {
    geminiConfigured: boolean;
    groqConfigured: boolean;
    geminiStored: boolean;
    groqStored: boolean;
    configuredAt: string | null;
    lastTest: LastTest;
};

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

export default function AiSettings({
    geminiConfigured,
    groqConfigured,
    geminiStored,
    groqStored,
    configuredAt,
    lastTest,
}: Props) {
    const geminiInput = useRef<HTMLInputElement>(null);
    const groqInput = useRef<HTMLInputElement>(null);

    const testProvider = (provider: 'gemini' | 'groq') => {
        router.post(
            AiSettingsController.test.url(),
            {
                provider,
                gemini_api_key: geminiInput.current?.value ?? '',
                groq_api_key: groqInput.current?.value ?? '',
            },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Chaves de IA" />

            <h1 className="sr-only">Chaves de IA</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Inteligência artificial"
                    description="Informe as chaves usadas para classificar compras importadas. Groq pode ficar em branco."
                />

                <div className="space-y-3 text-sm">
                    <ProviderStatus
                        name="Gemini"
                        configured={geminiConfigured}
                        lastTest={
                            lastTest?.provider === 'gemini' ? lastTest : null
                        }
                        onTest={() => testProvider('gemini')}
                    />
                    <ProviderStatus
                        name="Groq"
                        configured={groqConfigured}
                        lastTest={lastTest?.provider === 'groq' ? lastTest : null}
                        onTest={() => testProvider('groq')}
                    />
                </div>

                {configuredAt && (
                    <p className="text-muted-foreground text-xs">
                        Última alteração em {dateTime.format(new Date(configuredAt))}.
                    </p>
                )}

                <Form
                    {...AiSettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="gemini_api_key">
                                    Gemini
                                    {geminiStored ? (
                                        <span className="text-muted-foreground font-normal">
                                            {' '}
                                            (deixe em branco para manter a atual)
                                        </span>
                                    ) : null}
                                </Label>
                                <p className="text-muted-foreground text-xs">
                                    Lê a descrição da fatura e sugere
                                    estabelecimento e categoria. Chave do Google
                                    AI Studio.
                                </p>
                                <PasswordInput
                                    id="gemini_api_key"
                                    ref={geminiInput}
                                    name="gemini_api_key"
                                    autoComplete="off"
                                    placeholder={
                                        geminiStored
                                            ? 'Chave já cadastrada'
                                            : 'Cole a chave do Gemini'
                                    }
                                />
                                <InputError message={errors.gemini_api_key} />
                                {geminiStored ? (
                                    <label className="text-muted-foreground flex items-center gap-2 text-xs">
                                        <input
                                            type="checkbox"
                                            name="clear_gemini"
                                            value="1"
                                            className="border-input size-4 rounded-[4px]"
                                        />
                                        Remover a chave do Gemini
                                    </label>
                                ) : null}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="groq_api_key">
                                    Groq
                                    {groqStored ? (
                                        <span className="text-muted-foreground font-normal">
                                            {' '}
                                            (deixe em branco para manter a atual)
                                        </span>
                                    ) : null}
                                </Label>
                                <p className="text-muted-foreground text-xs">
                                    Reserva quando a cota do Gemini acaba. Crie
                                    em console.groq.com.
                                </p>
                                <PasswordInput
                                    id="groq_api_key"
                                    ref={groqInput}
                                    name="groq_api_key"
                                    autoComplete="off"
                                    placeholder={
                                        groqStored
                                            ? 'Chave já cadastrada'
                                            : 'Cole a chave do Groq'
                                    }
                                />
                                <InputError message={errors.groq_api_key} />
                                {groqStored ? (
                                    <label className="text-muted-foreground flex items-center gap-2 text-xs">
                                        <input
                                            type="checkbox"
                                            name="clear_groq"
                                            value="1"
                                            className="border-input size-4 rounded-[4px]"
                                        />
                                        Remover a chave do Groq
                                    </label>
                                ) : null}
                            </div>

                            <Button disabled={processing}>
                                {processing
                                    ? 'Salvando…'
                                    : 'Salvar chaves'}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

function ProviderStatus({
    name,
    configured,
    lastTest,
    onTest,
}: {
    name: string;
    configured: boolean;
    lastTest: LastTest;
    onTest: () => void;
}) {
    return (
        <div className="flex items-center justify-between gap-3 rounded-lg border px-3 py-2">
            <div>
                <p className="text-muted-foreground">{name}</p>
                <p
                    className={
                        configured
                            ? 'text-positive font-semibold'
                            : 'text-muted-foreground font-semibold'
                    }
                >
                    {configured ? 'Informado' : 'Faltando'}
                </p>
                {lastTest ? (
                    <p
                        className={
                            lastTest.ok
                                ? 'text-positive mt-1 text-xs'
                                : 'text-destructive mt-1 text-xs'
                        }
                    >
                        {lastTest.message}
                    </p>
                ) : null}
            </div>
            <Button type="button" variant="secondary" onClick={onTest}>
                Testar
            </Button>
        </div>
    );
}

AiSettings.layout = {
    breadcrumbs: [
        {
            title: 'Chaves de IA',
            href: edit(),
        },
    ],
};
