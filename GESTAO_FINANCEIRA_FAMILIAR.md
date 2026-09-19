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
- Conta
- Cartão
- Quantidade de parcelas
- Observação
- Origem
- Status

Uma compra parcelada deverá existir como uma única transação principal ligada às suas parcelas.

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
- Regra de geração

O objetivo é eliminar a necessidade de criar novamente os mesmos lançamentos todos os meses.

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
