
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

Na importação de faturas de cartão, uma compra só deve ser marcada como **conciliada** quando possuir uma categoria válida. O mesmo vale para receita e despesa vindas de extrato bancário. A categoria pode ser definida por regra determinística, histórico, heurística conhecida, sugestão de IA com confiança suficiente ou confirmação do usuário. Se nenhuma categoria puder ser determinada com segurança, a linha permanece pendente na Caixa de Entrada Financeira e não deve ser marcada como conciliada. Transferência entre contas próprias, pagamento de fatura e reembolso não usam categoria.

As regras determinísticas de classificação, tanto para extratos bancários quanto para linhas de fatura de cartão, possuem três níveis explícitos de automação: **Somente classificar**, que apenas preenche a classificação sugerida e mantém a entrada pendente; **Conciliar automaticamente**, que pode vincular somente um lançamento já existente quando a correspondência for inequívoca e nunca cria um novo; e **Criar e conciliar automaticamente**, que também pode criar pelo serviço do domínio, mas somente depois de procurar lançamento existente e confirmar que não há candidato relevante ou ambiguidade. Toda decisão automática baseada em regra deve deixar rastreabilidade mínima da regra aplicada, nível, resultado, score de correspondência quando houver, lançamento ou entidade relacionada, motivo e momento do processamento. Fluxos especiais, como pagamento de fatura, transferência e reembolso, continuam usando seus serviços próprios e não podem ser convertidos em receita ou despesa por uma regra genérica.

Pagamentos de cartão devem existir independentemente da presença da fatura no sistema. Quando um movimento bancário for identificado como pagamento de cartão e a fatura ainda não existir, o sistema deve registrar o pagamento vinculado ao cartão e à conta de origem, conciliar o movimento bancário e manter apenas o vínculo com a fatura como pendência. Esse pagamento não é uma nova despesa. Quando a fatura for importada, pagamentos pendentes do mesmo cartão devem ser vinculados automaticamente somente quando houver correspondência inequívoca; em casos ambíguos, o usuário deve poder vincular manualmente o pagamento à fatura.

Reembolso é um fato financeiro próprio, sempre vinculado à compra ou despesa original. A compra original não deve ser excluída nem ter seu valor destruído. Para análise gerencial, a despesa líquida corresponde ao valor original menos os reembolsos confirmados. Reembolso recebido em conta financeira gera entrada de caixa do tipo reembolso, não receita, e não reduz a fatura do cartão quando a compra original foi feita no cartão. Estorno recebido diretamente no cartão deve ser vinculado ao mesmo cartão e a uma fatura compatível, reduzindo o valor devido nessa fatura sem criar movimento bancário fictício.

Em compras parceladas, o reembolso permanece vinculado à transação principal. Para indicadores por competência, o valor reembolsado deve ser distribuído entre as parcelas de forma determinística, preservando o valor original da compra, das parcelas e das faturas. Importações e a listagem de conciliação não devem pesquisar automaticamente despesas candidatas a reembolso. Primeiro, o usuário identifica explicitamente uma entrada positiva como reembolso; somente então o sistema busca possíveis despesas de origem por valor, descrição, estabelecimento e proximidade de data para apoiar o vínculo. A confirmação do vínculo continua sendo uma ação explícita do usuário. Movimentos de reembolso já registrados podem continuar participando da conciliação normal como movimentos existentes.

---

## 35. Inteligência Artificial

A inteligência artificial será uma camada de apoio, e não a fonte de verdade financeira.

Possíveis usos:

- interpretar mensagens em linguagem natural;

---

## 36. Dashboard de Pagamentos e Fluxo Mensal

O Dashboard de Pagamentos é uma visão operacional de caixa e compromissos do mês, separada do dashboard geral de análise financeira.

Regras desta visão:

- **Pago** e **Recebido** usam a data efetiva de pagamento ou recebimento para representar o fluxo de caixa realizado.
- Transferência entre contas próprias não é pagamento nem recebimento. Ela não aparece em **Pago** ou **Recebido**. Se o mesmo movimento também existir como despesa ou receita, com a mesma descrição, valor, data e conta, ele não entra de novo nessas listas.
- **A pagar** e **A receber** representam compromissos ainda pendentes no período, usando vencimento ou data prevista quando disponível.
- Compras individuais de cartão não aparecem como obrigações separadas no **A pagar**. A obrigação exibida é a fatura, e as compras ficam disponíveis apenas no detalhamento expansível.
- O pagamento da fatura movimenta o caixa, mas não cria uma nova despesa.
- Parcelas vinculadas a uma fatura não podem ser somadas novamente à obrigação da fatura.
- O **saldo projetado do mês** é calculado como entradas realizadas e previstas menos saídas realizadas e previstas do próprio período. Ele não substitui o saldo patrimonial ou o saldo atual das contas mostrado em outras visões.
- Recorrências materializadas, parcelas, crediários e faturas futuras já conhecidas devem alimentar a projeção sem duplicar compra, parcela, fatura e pagamento.
- Todas as consultas permanecem isoladas pelo workspace ativo.


---

## 37. Fechamento Mensal de Importações

O fechamento mensal de importações é um checklist operacional por **workspace + origem financeira + período**, construído a partir das contas financeiras e cartões de crédito ativos, e não a partir da existência de arquivos.

Regras:

- toda conta ou cartão ativo deve aparecer no checklist mesmo quando nenhuma entrada tiver sido recebida;
- os estados operacionais de importação e conciliação são calculados a partir da cobertura e dos movimentos normalizados da origem;
- uma origem totalmente conciliada não é fechada automaticamente;
- **Fechado** representa confirmação explícita do usuário para aquele período;
- **Sem movimento** também é uma confirmação explícita do período, distinta de ausência de importação;
- contas devem considerar a cobertura temporal combinada de múltiplas entradas, sem assumir que a existência de um arquivo significa mês completo;
- cartões devem ser conferidos pelo ciclo/referência da fatura, e não pela data de upload;
- múltiplas importações podem participar da mesma origem e período;
- fechamento e eventual reabertura devem registrar usuário e data;
- todos os vínculos e consultas permanecem isolados pelo `workspace_id`.

A persistência do fechamento é independente do formato da entrada. PDF, CSV, OFX, Open Finance, Gmail, APIs ou outras fontes futuras devem alimentar a mesma camada normalizada de importação/conciliação antes de participar do checklist.
