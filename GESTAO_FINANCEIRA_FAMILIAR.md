
## 31. Direção de Produto e Arquitetura SaaS

Embora o primeiro uso seja financeiro familiar, o projeto deve nascer preparado para futura comercialização como SaaS.

A arquitetura deve considerar desde o início:

- múltiplos usuários;
- múltiplas famílias ou organizações;
- isolamento rigoroso dos dados;
- autenticação segura;
- auditoria de operações sensíveis;
- integrações externas;
- filas para processamento assíncrono;
- APIs;
- webhooks;
- possibilidade futura de planos e cobrança.

O conceito central de isolamento deverá ser um **workspace**.

Exemplos:

- Workspace: Família Silva
- Workspace: Família João
- Workspace: outra família ou organização no futuro

Os dados financeiros pertencem ao workspace, e não diretamente a um usuário isolado.

Um usuário poderá futuramente participar de um ou mais workspaces.

A aplicação deverá ser construída inicialmente como **monólito modular**, evitando microserviços prematuros, mas mantendo separação clara entre domínio financeiro, integrações, autenticação, importações e demais módulos.

---

## 32. Múltiplas Formas de Entrada de Dados

O sistema não deverá depender somente de digitação manual.

O motor financeiro deverá estar preparado para receber informações através de diferentes canais:

- interface web;
- PWA/mobile web;
- importação OFX;
- importação de faturas;
- WhatsApp;
- Gmail;
- inteligência artificial;
- Open Finance;
- outras APIs futuras.

Todas essas origens devem alimentar o **mesmo motor financeiro**.

A origem da informação deve ser armazenada para fins de rastreabilidade.

Exemplos:

- manual;
- ofx;
- cartão;
- gmail;
- whatsapp;
- open_finance;
- api;
- ia.

Nenhuma integração externa deverá implementar sua própria regra de criação de despesas, receitas, parcelas ou faturas.

---

## 33. Camada de Integrações e Normalização

Integrações externas não devem gravar diretamente nas tabelas centrais do domínio financeiro.

O fluxo conceitual deverá ser:

**Fonte externa → Conector → Evento/Entrada → Normalização → Validação → Serviço do domínio financeiro → Banco**

Para arquivos financeiros, a entrada deverá possuir uma etapa central de autodetecção antes do parser específico:

**Upload → inspeção do arquivo → detecção do documento/instituição → resolução de conta ou cartão → parser adequado → normalização → motor financeiro → conciliação**

A detecção deve priorizar regras determinísticas, estrutura do arquivo e identificadores objetivos. IA poderá ser adicionada posteriormente como segunda camada, sem gravar diretamente no domínio.

Quando a identificação for segura, o processamento segue automaticamente. Quando faltar tipo, conta, cartão, período ou houver layout não suportado, o arquivo deve permanecer preservado como documento pendente para confirmação mínima do usuário.

Vínculos confirmados entre identificadores persistentes do documento, como número de conta ou final do cartão, e entidades do workspace devem ser reaproveitados em importações futuras. Esse aprendizado pertence à aplicação e sempre deve respeitar o `workspace_id`.

Exemplos de fontes externas:

- Gmail;
- WhatsApp;
- Open Finance;
- OFX;
- CSV/XLSX;
- APIs externas.

Uma entrada poderá inicialmente ficar pendente de confirmação antes de gerar uma transação financeira.

Essa separação permitirá substituir fornecedores ou adicionar novos canais sem alterar as regras centrais do financeiro.

---

## 34. Caixa de Entrada Financeira

O produto deverá evoluir para trabalhar por exceção.

Informações recebidas automaticamente deverão poder entrar em uma **Caixa de Entrada Financeira**.

Exemplos:

- movimento bancário ainda não conciliado;
- fatura encontrada no Gmail;
- despesa informada pelo WhatsApp;
- transação recebida por Open Finance;
- possível duplicidade;
- classificação sugerida por IA;
- lançamento que exige confirmação do usuário.

O objetivo é que o usuário não precise reconstruir o financeiro manualmente.

Ele deverá revisar e resolver somente aquilo que exigir intervenção.

Na importação de faturas de cartão, uma compra só deve ser marcada como **conciliada** quando possuir uma categoria válida. A categoria pode ser definida por regra determinística, histórico, heurística conhecida, sugestão de IA com confiança suficiente ou confirmação do usuário. Se nenhuma categoria puder ser determinada com segurança, a linha permanece pendente na Caixa de Entrada Financeira e não deve ser marcada como conciliada.

Pagamentos de cartão devem existir independentemente da presença da fatura no sistema. Quando um movimento bancário for identificado como pagamento de cartão e a fatura ainda não existir, o sistema deve registrar o pagamento vinculado ao cartão e à conta de origem, conciliar o movimento bancário e manter apenas o vínculo com a fatura como pendência. Esse pagamento não é uma nova despesa. Quando a fatura for importada, pagamentos pendentes do mesmo cartão devem ser vinculados automaticamente somente quando houver correspondência inequívoca; em casos ambíguos, o usuário deve poder vincular manualmente o pagamento à fatura.

Reembolso é um fato financeiro próprio, sempre vinculado à compra ou despesa original. A compra original não deve ser excluída nem ter seu valor destruído. Para análise gerencial, a despesa líquida corresponde ao valor original menos os reembolsos confirmados. Reembolso recebido em conta financeira gera entrada de caixa do tipo reembolso, não receita, e não reduz a fatura do cartão quando a compra original foi feita no cartão. Estorno recebido diretamente no cartão deve ser vinculado ao mesmo cartão e a uma fatura compatível, reduzindo o valor devido nessa fatura sem criar movimento bancário fictício.

Em compras parceladas, o reembolso permanece vinculado à transação principal. Para indicadores por competência, o valor reembolsado deve ser distribuído entre as parcelas de forma determinística, preservando o valor original da compra, das parcelas e das faturas. Importações e conciliações devem sugerir reembolsos por valor, descrição, estabelecimento e proximidade de data, mas só podem concluir automaticamente quando a correspondência for inequívoca. Em caso de ambiguidade, a decisão permanece na Caixa de Entrada Financeira.

---

## 35. Inteligência Artificial

A inteligência artificial será uma camada de apoio, e não a fonte de verdade financeira.

Possíveis usos:

- interpretar mensagens em linguagem natural;