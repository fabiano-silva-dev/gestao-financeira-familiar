# Gestão Financeira Familiar — Documento Base do Projeto

## 1. Visão do Projeto

O **Gestão Financeira Familiar** é uma aplicação para controle financeiro pessoal e familiar, criada para centralizar despesas, receitas, cartões, pagamentos, recebimentos, recorrências, compromissos futuros e conciliações bancárias.

O projeto nasce para resolver um problema prático: hoje as informações financeiras ficam espalhadas entre extratos bancários, faturas de cartões, planilhas mensais e controles de pagamentos futuros.

A proposta é substituir esse conjunto de controles separados por uma **base financeira única e contínua**, onde mês e ano sejam apenas formas de visualizar as informações.

O sistema deve permitir entender rapidamente:

- o que foi gasto;
- quando a despesa aconteceu;
- quanto ainda será pago;
- quando o dinheiro efetivamente saiu da conta;
- quanto das próximas rendas já está comprometido;
- como cada pagamento se relaciona com a despesa original.

---

## 2. Objetivo Principal

Permitir uma gestão financeira familiar simples, rápida e confiável.

A aplicação deve responder perguntas como:

- Quanto gastamos neste mês?
- Quanto efetivamente saiu das contas bancárias?
- Quanto ainda temos para pagar?
- Quanto temos para receber?
- Quanto das próximas faturas já está comprometido?
- Quanto existem de parcelas futuras?
- Quanto da renda dos próximos meses já está comprometido?
- Quais despesas ainda precisam ser classificadas?
- Quais movimentos bancários ainda não foram conciliados?
- Qual é a previsão financeira dos próximos 30, 60, 90 dias ou mais?

---

## 3. Princípio Central

O sistema deve separar conceitos que normalmente são misturados em controles financeiros simples.

Uma mesma compra pode possuir diferentes datas e impactos.

Os quatro principais conceitos são:

### 3.1 Data da compra ou fato financeiro

Representa o momento em que a compra, despesa ou receita foi originada.

Exemplo:

Tênis comprado em 19/09/2026 por R$ 300,00.

---

### 3.2 Competência ou impacto no orçamento

Representa o mês em que determinada despesa deve aparecer na visão gerencial.

Em uma compra parcelada, cada parcela pode comprometer um mês diferente.

Exemplo:

Tênis de R$ 300,00 em 3 parcelas:

- Setembro: R$ 100,00
- Outubro: R$ 100,00
- Novembro: R$ 100,00

A aplicação também deve manter a informação de que a compra original foi de R$ 300,00 e ocorreu em setembro.

---

### 3.3 Vencimento ou obrigação

Representa quando determinado valor deverá ser efetivamente pago ou recebido.

No cartão de crédito, a competência de uma parcela pode ser diferente do mês em que a fatura será paga.

Exemplo:

- Competência setembro → fatura outubro
- Competência outubro → fatura novembro
- Competência novembro → fatura dezembro

---

### 3.4 Caixa

Representa o momento em que o dinheiro efetivamente entra ou sai de uma conta financeira.

O pagamento da fatura de um cartão não deve gerar uma nova despesa.

Ele deve apenas liquidar uma obrigação formada por compras que já foram registradas anteriormente.

---

## 4. Exemplo — Compra no Mercado

Compra realizada em setembro:

R$ 1.000,00 no cartão de crédito.

Visões:

- Compra realizada: setembro
- Despesa: setembro
- Fatura: outubro
- Saída do banco: outubro

Quando a fatura for paga em outubro, o pagamento não deverá criar uma nova despesa de R$ 1.000,00.

A despesa já foi reconhecida na compra realizada em setembro.

---

## 5. Exemplo — Compra Parcelada

Tênis comprado em setembro:

- Valor total: R$ 300,00
- Parcelamento: 3x de R$ 100,00

### Visão gerencial

- Setembro: R$ 100,00
- Outubro: R$ 100,00
- Novembro: R$ 100,00

### Visão das faturas/pagamentos

- Outubro: R$ 100,00
- Novembro: R$ 100,00
- Dezembro: R$ 100,00

O sistema deve preservar três informações:

1. a compra original de R$ 300,00;
2. o comprometimento mensal de R$ 100,00;
3. quando cada parcela será efetivamente paga.

---

## 6. Base Financeira Única

O sistema não deverá possuir uma estrutura separada por mês.

Não deverão existir conceitos como:

- planilha setembro;
- planilha outubro;
- pagamentos setembro;
- despesas outubro.

Deverá existir uma única base de dados financeira.

Setembro, outubro, novembro ou qualquer outro período serão filtros sobre essa base.

Isso permitirá visualizar passado, presente e futuro sem criar novos controles todos os meses.

---

## 7. Cadastros Principais

### 7.1 Pessoas da família

O sistema deverá permitir identificar quem originou determinada despesa ou receita.

Exemplos:

- Fabiano
- Lidiane
- Luiza
- Felipe
- Família

Esse cadastro deverá ser opcional no lançamento.

---

### 7.2 Contas financeiras

Representam onde o dinheiro está.

Exemplos:

- Conta corrente
- Conta digital
- Poupança
- Carteira
- Dinheiro
- Conta de investimento

Campos principais:

- Nome
- Instituição
- Tipo
- Saldo inicial
- Ativa/inativa

---

### 7.3 Cartões de crédito

Campos principais:

- Nome
- Instituição
- Final do cartão
- Titular
- Limite
- Dia de fechamento
- Dia de vencimento
- Conta utilizada para pagamento
- Forma padrão de pagamento da fatura
- Instruções de pagamento
- Ativo/inativo

---

### 7.4 Categorias e subcategorias

Exemplos:

- Mercado
- Moradia
- Água
- Energia
- Internet
- Educação
- Saúde
- Transporte
- Veículos
- Vestuário
- Lazer
- Restaurantes
- Assinaturas
- Impostos
- Viagens
- Receitas
- Salários
- Distribuição de lucros
- Outros

O sistema deverá permitir categoria e subcategoria.

Exemplo:

Categoria: Veículos  
Subcategoria: Combustível

---

## 8. Transação Financeira

A transação representa o fato financeiro original.

Pode ser:

- Receita
- Despesa
- Transferência

Campos conceituais:

- Tipo
- Data da transação
- Descrição
- Valor total
- Categoria
- Subcategoria
- Pessoa da família
- Forma de pagamento
- Favorecido ou beneficiário
- Instruções de pagamento, como chave PIX ou referência
- Conta
- Cartão
- Quantidade de parcelas
- Observação
- Origem
- Status

Uma compra parcelada deverá existir como uma única transação principal ligada às suas parcelas.

Toda despesa deverá permitir informar a forma de pagamento. Em despesas a prazo,
parceladas ou recorrentes, essa informação deverá acompanhar os vencimentos futuros,
junto com o favorecido e as instruções necessárias para pagar. Esses dados funcionam
como padrão e podem ser alterados em uma ocorrência específica.

---

## 9. Parcelas

Uma transação poderá gerar uma ou mais parcelas.

Cada parcela deverá possuir:

- Número da parcela
- Quantidade total de parcelas
- Valor
- Competência
- Fatura associada
- Data de vencimento
- Data prevista de pagamento
- Data efetiva de pagamento
- Status

Exemplo:

Tênis R$ 300,00:

- Parcela 1/3 — R$ 100,00
- Parcela 2/3 — R$ 100,00
- Parcela 3/3 — R$ 100,00

Todas devem permanecer vinculadas à compra original.

---

## 10. Faturas de Cartão

A fatura deverá ser uma entidade própria.

Ela agrupa todas as compras e parcelas correspondentes a determinado cartão e ciclo.

Campos principais:

- Cartão
- Mês de referência
- Data de fechamento
- Data de vencimento
- Valor calculado
- Valor informado pela operadora
- Valor pago
- Data de pagamento
- Situação
- Movimento bancário associado

Situações possíveis:

- Aberta
- Fechada
- Paga
- Parcialmente paga
- Vencida

---

## 11. Pagamento da Fatura

O pagamento da fatura não é uma nova despesa.

Fluxo:

**Compra → Parcela → Fatura → Pagamento → Movimento bancário**

Exemplo:

A fatura Nubank possui R$ 4.000,00 em compras.

Quando aparecer no extrato:

Pagamento Nubank — R$ 4.000,00

O sistema deve vincular esse movimento à fatura.

Resultado:

- despesas continuam classificadas individualmente;
- fatura fica paga;
- saída financeira fica registrada;
- nenhuma despesa é duplicada.

---

## 12. Movimentos Bancários

Movimentos bancários representam aquilo que realmente aconteceu nas contas.

Exemplos:

- PIX
- TED
- boleto
- débito automático
- saque
- depósito
- transferência
- pagamento de cartão
- tarifa bancária
- rendimento

Campos principais:

- Conta
- Data
- Descrição bancária
- Valor
- Tipo
- Identificador externo
- Origem da importação
- Conciliado ou não

---

## 13. Conciliação

A conciliação deve ligar o movimento real do banco à informação financeira existente no sistema.

Exemplos:

Conta de energia ↔ débito bancário.

Receita prevista ↔ crédito no banco.

Fatura Nubank ↔ pagamento da fatura no banco.

Transferência entre contas ↔ saída de uma conta + entrada na outra.

O sistema deverá permitir:

- conciliação manual;
- sugestão automática;
- confirmação rápida;
- identificação de diferenças.

---

## 14. Importação de OFX

A importação OFX será uma das primeiras automações do sistema.

Fluxo desejado:

1. Usuário seleciona a conta.
2. Importa o arquivo OFX.
3. Sistema identifica movimentos já existentes.
4. Evita duplicidades.
5. Importa novos movimentos.
6. Procura lançamentos compatíveis.
7. Sugere conciliações.
8. Usuário trata apenas exceções.

Decisões adotadas para a primeira versão:

- o arquivo original é preservado em armazenamento privado para auditoria;
- cada envio gera histórico com status, período e contadores de processamento;
- os itens importados ficam em uma área bancária intermediária e não criam automaticamente receitas, despesas ou movimentos no livro financeiro;
- a duplicidade do arquivo é verificada pelo conteúdo, independentemente do nome recebido;
- a duplicidade dos itens usa o identificador bancário `FITID` quando disponível e uma impressão determinística dos dados como alternativa;
- somente a conciliação transforma ou vincula o registro bancário a uma obrigação, fatura, transferência ou lançamento do sistema.

---

## 15. Importação de Faturas

O sistema deverá permitir importação de faturas dos cartões.

Prioridade inicial:

- CSV
- XLS
- XLSX
- outros formatos estruturados fornecidos pelos bancos

PDF poderá ser implementado posteriormente.

Informações desejadas:

- data da compra;
- estabelecimento;
- valor;
- número da parcela;
- total de parcelas;
- cartão;
- fatura.

Decisões adotadas para a primeira versão:

- o usuário informa o cartão, o mês de vencimento da fatura e a convenção de sinal usada no arquivo;
- arquivos CSV, XLSX e exportações XLS estruturadas em XML, HTML ou texto delimitado são normalizados pelo mesmo importador;
- arquivos XLS binários legados devem ser convertidos para XLSX ou CSV antes do envio;
- o arquivo original é preservado em armazenamento privado e cada processamento mantém histórico auditável;
- as linhas importadas são vinculadas à entidade de fatura em uma área intermediária, preservando número e total de parcelas;
- uma linha de fatura parcelada não cria uma despesa independente nem multiplica compras já existentes;
- arquivos reenviados e linhas sobrepostas são tratados de forma idempotente por cartão e mês de referência;
- o total normalizado atualiza o valor informado pela operadora somente enquanto a fatura estiver aberta;
- a conciliação posterior será responsável por vincular cada linha importada a uma parcela existente ou criar a compra por meio do motor financeiro.

---

## 16. Recorrências

O sistema deverá permitir configurar despesas e receitas recorrentes.

Exemplos:

- Escola
- Internet
- Academia
- Financiamento
- Assinaturas
- Energia estimada
- Receitas fixas
- Salários
- Distribuições
- Seguros

Campos conceituais:

- Tipo
- Periodicidade
- Valor
- Data inicial
- Data final opcional
- Dia de vencimento
- Categoria
- Conta ou cartão
- Forma de pagamento
- Favorecido ou beneficiário
- Instruções de pagamento, como chave PIX ou referência
- Regra de geração

O objetivo é eliminar a necessidade de criar novamente os mesmos lançamentos todos os meses.

Exemplos:

- atividade esportiva recorrente → PIX → favorecido e chave cadastrados;
- fatura de cartão → boleto como forma padrão de pagamento.

---

## 17. Transferências entre Contas

Transferências não são receitas nem despesas.

Exemplo:

Transferir R$ 2.000,00 do Sicredi para Nubank.

Financeiramente:

- Sicredi: saída de R$ 2.000,00
- Nubank: entrada de R$ 2.000,00
- Resultado financeiro familiar: R$ 0,00

A aplicação deve identificar e vincular as duas pontas da transferência.

---

## 18. Comprometimento Futuro

Esta deverá ser uma das principais funcionalidades do projeto.

O sistema deve conseguir mostrar quanto dos meses seguintes já está comprometido.

Exemplo:

### Outubro

- Parcelas de cartão: R$ 2.500
- Escola: R$ 1.500
- Financiamento: R$ 1.200
- Internet: R$ 150
- Assinaturas: R$ 300
- Outros compromissos: R$ 1.700

Total já comprometido: R$ 7.350.

Esse valor deve ser visualizado independentemente de novas despesas que ainda poderão ocorrer.

---

## 19. Visões Principais

A mesma base deverá fornecer diferentes formas de análise.

### 19.1 Despesas por competência

Responder:

Quanto foi consumido ou comprometido em cada mês?

---

### 19.2 Fluxo de caixa

Responder:

Quanto efetivamente entrou ou saiu das contas?

---

### 19.3 Compromissos futuros

Responder:

Quanto já existe para pagar nos próximos meses?

---

### 19.4 Cartões

Mostrar:

- fatura atual;
- próxima fatura;
- compras recentes;
- parcelamentos;
- parcelas futuras;
- limite utilizado;
- limite disponível;
- previsão de próximas faturas.

---

### 19.5 Conciliação

Mostrar somente situações que exigem ação.

Exemplos:

- movimento bancário sem origem identificada;
- lançamento pago sem movimento bancário correspondente;
- diferença entre fatura calculada e fatura importada;
- despesa sem categoria;
- possível duplicidade.

O sistema deverá trabalhar preferencialmente por exceção.

---

## 20. Dashboard

O dashboard inicial deverá ser objetivo.

Informações prioritárias:

- Saldo total das contas
- Receitas do mês
- Despesas do mês
- Resultado do mês
- Valor efetivamente pago
- Valor ainda a pagar
- Faturas atuais
- Próximas faturas
- Parcelamentos futuros
- Comprometimento dos próximos meses
- Movimentos não conciliados

Evitar excesso de gráficos e indicadores no MVP.

---

## 21. Estados dos Lançamentos

Estados inicialmente sugeridos:

- Previsto
- Confirmado
- Vencido
- Pago
- Recebido
- Parcial
- Conciliado
- Cancelado

A quantidade de estados deverá permanecer simples.

---

## 22. Regras Automáticas

O sistema poderá criar regras para facilitar classificações futuras.

Exemplos:

SUPERMERCADO X → Mercado

RGE → Energia elétrica

NETFLIX → Assinaturas

POSTO X → Combustível

ESCOLA X → Educação

Inicialmente as regras podem ser definidas pelo usuário.

Posteriormente o sistema poderá sugeri-las baseado no histórico.

---

## 23. MVP

A primeira versão deverá priorizar:

1. Autenticação
2. Pessoas da família
3. Contas financeiras
4. Cartões
5. Categorias
6. Receitas
7. Despesas
8. Compras parceladas
9. Parcelas
10. Faturas
11. Pagamentos
12. Recebimentos
13. Transferências
14. Recorrências
15. Importação OFX
16. Conciliação
17. Dashboard
18. Comprometimento futuro

---

## 24. Fora do MVP

Não implementar inicialmente:

- Open Finance;
- investimentos avançados;
- controle patrimonial completo;
- imposto de renda;
- contabilidade;
- emissão fiscal;
- integração bancária em tempo real;
- inteligência artificial complexa;
- BI avançado;
- controle financeiro empresarial.

Esses recursos poderão ser avaliados depois de o sistema estar sendo utilizado na rotina familiar.

---

## 25. Experiência Desejada

O usuário não deve precisar passar horas organizando o financeiro.

A ferramenta deverá automatizar o máximo possível.

Fluxo desejado:

1. lançamentos recorrentes já existem;
2. compras parceladas já projetam meses futuros;
3. extrato bancário é importado;
4. faturas são importadas;
5. sistema sugere classificações;
6. sistema sugere conciliações;
7. usuário resolve exceções;
8. dashboards e previsões ficam automaticamente atualizados.

Meta operacional:

**conseguir revisar e fechar o financeiro familiar em aproximadamente 10 minutos por semana.**

---

## 26. Filosofia de Desenvolvimento

Prioridades:

1. regras financeiras corretas;
2. modelo de dados consistente;
3. facilidade de lançamento;
4. automação;
5. conciliação;
6. visão futura;
7. interface simples;
8. relatórios.

A aplicação não deve tentar se transformar em um ERP financeiro completo durante o MVP.

O foco é resolver muito bem o controle financeiro familiar.

---

## 27. Fases do Desenvolvimento

### Fase 1 — Fundação

- estrutura do projeto;
- autenticação;
- usuários;
- pessoas da família;
- contas;
- cartões;
- categorias.

### Fase 2 — Motor Financeiro

- receitas;
- despesas;
- transações;
- parcelas;
- competências;
- pagamentos;
- recebimentos;
- transferências.

### Fase 3 — Cartões

- compras no cartão;
- parcelamentos;
- fechamento;
- faturas;
- vencimentos;
- pagamento da fatura.

### Fase 4 — Recorrências e Previsões

- receitas recorrentes;
- despesas recorrentes;
- geração automática;
- compromissos futuros;
- projeções mensais.

### Fase 5 — Importações

- OFX;
- faturas;
- prevenção de duplicidades.

### Fase 6 — Conciliação

- conciliação manual;
- sugestões automáticas;
- regras;
- tratamento de exceções.

### Fase 7 — Gestão

- dashboard;
- análise mensal;
- fluxo de caixa;
- compromissos futuros;
- comparativos;
- histórico.

---

## 28. Casos que deverão possuir regras específicas

Após o MVP básico, deverá existir documentação detalhada para:

- estorno de compra;
- compra cancelada;
- devolução parcial;
- antecipação de parcelas;
- pagamento parcial da fatura;
- juros do cartão;
- parcelamento de fatura;
- alteração do vencimento;
- despesa dividida entre categorias;
- despesa dividida entre pessoas;
- pagamento antecipado;
- pagamento em atraso;
- receita parcial;
- reembolso;
- transferência entre contas;
- saque;
- dinheiro em espécie.

Essas situações devem ser tratadas por regras de negócio documentadas, evitando soluções improvisadas diretamente nas telas.

---

## 29. Critério de Sucesso

O sistema estará cumprindo seu objetivo quando for possível:

- conhecer o saldo real disponível;
- saber quanto a família gastou;
- separar gasto de pagamento;
- identificar despesas parceladas;
- conhecer as próximas faturas;
- saber quanto dos próximos meses já está comprometido;
- projetar receitas e despesas;
- importar extratos;
- importar faturas;
- reconciliar os movimentos;
- reduzir lançamentos repetitivos;
- eliminar planilhas mensais separadas;
- fechar o financeiro rapidamente.

---

## 30. Princípio para Decisões Futuras

Sempre que uma nova funcionalidade for proposta, deverá ser feita a pergunta:

> Esta funcionalidade reduz o trabalho manual ou aumenta a clareza sobre a situação financeira da família?

Se não cumprir pelo menos um desses objetivos, provavelmente não deverá ser priorizada.

---

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

---

## 35. Inteligência Artificial

A inteligência artificial será uma camada de apoio, e não a fonte de verdade financeira.

Possíveis usos:

- interpretar mensagens em linguagem natural;
- interpretar áudio;
- extrair informações de comprovantes ou documentos;
- sugerir categorias;
- identificar estabelecimento;
- sugerir conciliações;
- detectar possíveis duplicidades ou anomalias;
- transformar texto não estruturado em dados estruturados.

Exemplo:

> "Gastei R$ 185,90 no posto hoje no Nubank."

Pode ser interpretado como uma sugestão de:

- Tipo: despesa
- Valor: R$ 185,90
- Categoria: combustível
- Meio de pagamento: cartão Nubank
- Data: hoje

Quando houver ambiguidade relevante, a IA deverá solicitar ou exigir confirmação antes da gravação definitiva.

A lógica financeira central nunca deverá depender exclusivamente da resposta de um modelo de IA.

Sempre que possível, o resultado da IA deverá ser convertido para estruturas tipadas e validadas pela aplicação.

O projeto deverá evitar dependência rígida de um único provedor de IA.

---

## 36. Integração com WhatsApp

O WhatsApp poderá funcionar como uma interface rápida para alimentar e consultar o sistema.

Possíveis operações futuras:

- informar uma despesa;
- informar uma receita;
- enviar áudio;
- enviar foto de comprovante;
- enviar documento;
- consultar saldo;
- consultar fatura;
- consultar compromissos futuros.

Fluxo desejado:

**WhatsApp → Webhook → Evento → Fila → Interpretação → Validação → Serviço financeiro → Confirmação**

O webhook deverá responder rapidamente e o processamento mais pesado deverá ocorrer de forma assíncrona.

Eventos deverão possuir identificadores externos para evitar processamento duplicado.

---

## 37. Integração com Gmail

O usuário poderá futuramente conectar uma conta Google através de OAuth.

Possíveis usos:

- localizar faturas;
- localizar boletos;
- localizar contas;
- localizar comprovantes;
- identificar documentos financeiros;
- importar anexos relevantes.

O acesso deverá utilizar o menor conjunto de permissões possível.

Tokens e credenciais deverão ser armazenados de forma criptografada.

E-mails não deverão virar automaticamente transações definitivas apenas por terem sido encontrados.

O fluxo preferencial será:

**Gmail → Entrada financeira → identificação/classificação → confirmação ou regra confiável → domínio financeiro**

---

## 38. Open Finance

Open Finance não faz parte do primeiro MVP, mas a arquitetura deverá estar preparada para recebê-lo sem reestruturação do motor financeiro.

O objetivo futuro é permitir a sincronização automatizada de informações como:

- contas bancárias;
- saldos;
- movimentações;
- cartões;
- faturas;
- outras informações disponibilizadas pelo provedor contratado.

A integração deverá ser realizada através de uma camada de provedor/adaptador.

O domínio financeiro não deverá conhecer detalhes específicos de um agregador ou instituição.

Exemplo conceitual:

**Open Finance Provider → Adapter → Evento normalizado → Motor financeiro**

Isso permitirá trocar fornecedores ou suportar mais de um provedor no futuro.

---

## 39. Conexões Externas

A aplicação deverá possuir um conceito próprio de conexões externas por workspace.

Uma conexão poderá representar:

- Google/Gmail;
- WhatsApp;
- Open Finance;
- outro provedor futuro.

Informações conceituais:

- workspace;
- tipo;
- provedor;
- status;
- identificador externo;
- credenciais criptografadas;
- expiração;
- última sincronização;
- metadados necessários.

Segredos, access tokens e refresh tokens nunca deverão ser armazenados em texto puro ou expostos em logs.

---

## 40. Eventos de Integração e Idempotência

Toda integração baseada em webhook, sincronização ou importação deverá considerar que o mesmo evento pode chegar mais de uma vez.

O sistema deverá preservar identificadores externos sempre que disponíveis.

Processar duas vezes o mesmo evento não deverá gerar duas despesas, dois movimentos ou duas faturas.

Eventos externos deverão possuir histórico suficiente para:

- identificar origem;
- verificar processamento;
- registrar falha;
- permitir reprocessamento seguro;
- auditar o que originou uma informação financeira.

---

## 41. Tecnologia e Diretrizes Estruturais

Stack inicialmente recomendada:

- Backend: Laravel atual estável;
- PHP: versão moderna suportada pela versão do Laravel escolhida;
- Frontend: React + TypeScript;
- Integração web: Inertia;
- UI: Tailwind CSS e biblioteca de componentes compatível;
- Banco principal: **PostgreSQL (decisão definitiva do projeto)**;
- Filas/cache: Redis;
- Processamento assíncrono: Laravel Queue;
- Monitoramento das filas: Horizon;
- Armazenamento de arquivos: S3 ou serviço compatível;
- Arquitetura: monólito modular;
- Multi-tenant: workspace desde o início;
- Interface: responsiva e mobile-first.

Evitar dependência desnecessária de tecnologias legadas do Eficere atual.

A simplicidade operacional continua sendo prioridade: utilizar tecnologia robusta, madura e comercialmente sustentável sem criar complexidade arquitetural antecipada.

---

## 42. Segurança e Robustez

Por tratar informações financeiras e credenciais de integrações, segurança deve ser requisito estrutural.

Princípios mínimos:

- isolamento obrigatório por workspace;
- autenticação segura;
- autorização em todas as operações;
- criptografia de tokens e credenciais;
- HTTPS obrigatório;
- proteção contra CSRF e ataques comuns da web;
- rate limiting;
- validação de assinatura ou autenticidade dos webhooks quando disponível;
- auditoria de operações financeiras relevantes;
- backups automatizados;
- testes periódicos de restauração;
- logs sem dados sensíveis;
- controle de acesso aos arquivos;
- idempotência em integrações e importações.

Segurança não deverá ser tratada como funcionalidade posterior.

---

## 43. Decisão de Banco de Dados

O banco de dados principal do projeto será **PostgreSQL**.

Esta é uma decisão arquitetural definida para o produto, e não apenas uma sugestão de stack.

Motivos principais:

- forte integridade relacional;
- excelente suporte a transações;
- recursos robustos para dados financeiros;
- suporte a `JSONB` para metadados e integrações;
- bons recursos de índices e consultas;
- possibilidade futura de reforço de isolamento por workspace com recursos do próprio PostgreSQL;
- maturidade e adequação para aplicações SaaS.

Diretrizes:

- não criar abstrações desnecessárias apenas para manter compatibilidade com MySQL;
- utilizar os recursos nativos do PostgreSQL quando trouxerem benefício real;
- armazenar valores monetários usando tipos decimais de precisão fixa, nunca `float` ou `double`;
- migrations devem ser compatíveis com PostgreSQL;
- consultas, índices e constraints devem priorizar consistência financeira e integridade dos dados.

O Eficere legado continuará independente em MySQL. Esta decisão é específica para o projeto Gestão Financeira Familiar.

---

## 44. Direção Visual

A direção visual inicial aprovada para a plataforma é **Confiança Serena**.

Ela utiliza azul-marinho, verde-petróleo, azul-aqua claro, âmbar e fundos claros
para transmitir segurança, organização e tranquilidade, sem assumir a aparência
fria de um aplicativo bancário.

Princípios principais:

- interface moderna, limpa e com boa densidade de informação;
- navegação lateral azul-marinho no desktop;
- verde-petróleo como identidade e destaque de ações;
- cards claros, bordas suaves e sombras discretas;
- formulários objetivos e adaptados para celular;
- dashboard focado em saldo, receitas, despesas, projeções, vencimentos e
  transações recentes;
- cores semânticas usadas com moderação e acessibilidade;
- preservação do suporte a tema escuro.

A especificação detalhada, os tokens sugeridos e o mockup aprovado estão em:

- [`docs/design/CONFIANCA_SERENA.md`](docs/design/CONFIANCA_SERENA.md)

O mockup é uma referência de direção visual, e não uma especificação pixel a
pixel ou uma fonte de regras financeiras.

---

## 45. Decisões de Implementação — Liquidação e Recorrências

A confirmação do fato financeiro e a liquidação de caixa são estados distintos.

Para receitas e despesas fora do cartão:

- `transaction_date` representa a data do fato financeiro;
- `competence_date` representa a competência gerencial;
- `due_date` representa o vencimento;
- `settled_on` representa a data efetiva de pagamento ou recebimento;
- movimentos de conta só são criados quando o lançamento estiver confirmado e possuir `settled_on`.

Para recorrências:

- a regra recorrente é uma entidade própria do workspace;
- cada ocorrência gerada mantém vínculo com sua recorrência de origem;
- a combinação recorrência + data da ocorrência deve ser única para impedir duplicidades;
- recorrências fora do cartão podem gerar compromissos planejados antecipadamente;
- recorrências no cartão só materializam a compra quando a data da ocorrência chega;
- projeções futuras de recorrências no cartão não criam faturas antecipadamente;
- pausar uma recorrência remove apenas compromissos futuros ainda planejados, preservando histórico e fatos já confirmados;
- a rotina automática de geração deve ser segura para reexecução e trabalhar de forma idempotente.

A projeção de recorrências é uma visão de planejamento e não substitui o fluxo de caixa real nem a fatura efetivamente formada.
