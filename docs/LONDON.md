# ActualTime London — ajuste de períodos anteriores

Base: **ActualTime 3.2.0 / GLPI 10.0.x**. Versão desta entrega: **3.2.6-london.4**.
Projeto original e créditos: [TICGAL/ActualTime](https://github.com/ticgal/actualtime).
Mantida a licença AGPL v3 ou posterior.

## Entrega atual

Ao editar um apontamento em **Modify timers** e antecipar seu início, o período
editado passa a prevalecer sobre períodos anteriores encerrados da **mesma tarefa**:

- Um período totalmente coberto é excluído.
- Um período parcialmente coberto passa a terminar no novo início.
- O apontamento editado mantém seu ID, técnico e término; se estava ativo,
  continua ativo. As durações e o total são recalculados.

Exemplo, em um dia passado: A **08:00–08:30** e B **08:30–09:00**.
Alterar o início de B para **08:15** deixa A **08:00–08:15** e B
**08:15–09:00**, totalizando uma hora. Alterar B para começar às **08:00**
exclui A e deixa B **08:00–09:00**. Um recuo maior pode excluir vários períodos
e encurtar apenas o trecho restante do período anterior.

Salvar realiza o ajuste em uma operação. O histórico registra o autor, os
horários alterados e qual apontamento prevaleceu. Na exclusão automática,
preserva também os dados do período removido; seu registro de primeira
modificação é removido junto. O primeiro registro original de um período apenas
encurtado é mantido.

Para sobrescrever períodos anteriores, altere um apontamento por vez e salve.
Se um período afetado tiver mudado em outra aba ou não estiver no editor aberto,
a gravação pede revisão dos valores atuais. Em falha, toda a operação é desfeita.
Outras tarefas, períodos posteriores e a criação manual mantêm suas validações.
Uma sobreposição que envolva intervalos fora desse recuo não é resolvida por
exclusão/encurtamento automático.

## Estatísticas e relatórios

As quatro funções foram homologadas nas etapas anteriores. Esta revisão corrige
diferenças encontradas nas estatísticas e nos relatórios opcionais do Reports.

Nas estatísticas de chamado/problema/mudança, o total real geral e a soma por
técnico incluem os mesmos segundos de cronômetros ativos, no instante em que a
tela é aberta ou recarregada. O tempo real fica com o técnico registrado no
apontamento, inclusive depois da troca do responsável da tarefa. A duração da
tarefa continua vinculada ao responsável atual, pois o GLPI mantém um único
valor de duração por tarefa. A tabela mostra essa distinção.

Os relatórios **ActualTimeTotal** e **ActualTimeUser**, quando o plugin Reports
estiver em uso, passam a consultar a estrutura atual de apontamentos. Vários
períodos da mesma tarefa e vários solicitantes não multiplicam sua duração.
O relatório por técnico preserva quem realizou cada período. O modo por
solicitante considera cada chamado uma vez para cada solicitante distinto.

Esses relatórios mantêm o filtro pela **data de fechamento** e consideram
chamados fechados, não excluídos e pertencentes às entidades ativas. A permissão
própria do relatório no Reports continua necessária. Tarefas sem apontamentos
têm tempo real zero. A diferença percentual segue as estatísticas:
**100 × (duração da tarefa − tempo real) / duração da tarefa**; com duração zero,
o percentual exibido é zero.

Nomes de técnicos com aspas e outros caracteres são escapados sem interromper
a montagem da tela. A revisão não altera o esquema do banco.

## Funções homologadas

O mesmo técnico pode iniciar cronômetros em **tarefas diferentes ao mesmo
tempo**, no mesmo chamado ou em chamados/problemas/mudanças/projetos distintos.
Cada tarefa acumula seu próprio tempo integralmente: duas ativas por 10 minutos
registram 10 minutos em cada uma. O total dos apontamentos pode, portanto,
superar o tempo corrido. Não há divisão automática entre tarefas.

Iniciar, pausar, retomar ou encerrar atua somente na tarefa escolhida. A mesma
tarefa continua aceitando apenas um cronômetro ativo, inclusive quando o início
é solicitado em duas abas. Tarefas de tipos diferentes com o mesmo número de ID
têm controles e contadores separados.

O aviso **Tarefas em execução** lista todos os cronômetros do usuário que o
perfil atual pode consultar. Cada link abre a tarefa correspondente. Recarregar
a página recupera os tempos do servidor. Mudanças entre abas são sinalizadas
no navegador; uma consulta a cada 10 segundos, enquanto a página está visível,
também atualiza os cronômetros. Respostas anteriores a uma ação não reativam
contadores já pausados. Desativar o aviso mantém os contadores das tarefas.

Os controles exigem permissão de atualização da tarefa, acesso ao item pai e
a atribuição ou autorização do técnico. A permissão **Modify timers** continua
separada: ela é usada para criar, editar ou excluir apontamentos. Permanecem as
restrições de planejamento, estados e integrações já existentes no início.

Em chamados, problemas e mudanças, encerrar mantém a conclusão da tarefa
correspondente. Em projetos, encerrar o cronômetro mantém o estado de projeto
configurado e atualiza a duração efetiva; não escolhe um estado final arbitrário.

### Integração com AutoStatus

A versão **3.2.6-london.2** publica o evento GLPI
`actualtime_timer_state_changed` após uma operação nativa bem-sucedida de início,
retomada, pausa ou encerramento. O evento sai depois de `withTaskLock` retornar,
incluindo a confirmação da transação quando ela pertence à operação. Falhas de
permissão, gravação ou confirmação não geram o evento. Consultas dos contadores e
criação/edição/exclusão de períodos históricos também não geram esse evento.

O payload contém `action` (`start`, `pause` ou `stop`), `itemtype`, `items_id`
(ID da tarefa), `timer_id` (0 se encerrar uma tarefa já pausada) e `origin`.
Os consumidores são opcionais: sem AutoStatus ativo, o cronômetro funciona
normalmente; uma exceção no consumidor é registrada em `php-errors` e não muda
o resultado de uma operação de cronômetro já gravada.

O **AutoStatus 1.4.0** recebe esse evento. Habilite a integração na configuração
do AutoStatus e escolha os status de início e de pausa/encerramento. O padrão é
**Em atendimento** ao iniciar/retomar e **Pendente** quando o último cronômetro do
chamado parar. Com **Aguardar todos os cronômetros do chamado = Sim**, parar uma
tarefa não aplica pendente enquanto qualquer outro técnico/tarefa do mesmo
chamado estiver rodando. Problemas, mudanças e projetos são ignorados pelo AutoStatus.
Encerrar continua concluindo a tarefa no ActualTime; não soluciona o chamado.

Atualize os dois plugins nas pastas existentes, sem desinstalar, primeiro no
espelho com backup. A versão anterior do AutoStatus, que apenas observa a criação
de tarefas, não acompanha os controles do cronômetro. As regras de status e
acompanhamentos existentes permanecem configuráveis no AutoStatus.

```bash
php tests/regression/timer-events.php
AUTOSTATUS_PLUGIN_DIR=../autostatus php tests/regression/timer-events.php
```

O segundo comando executa o AutoStatus real junto com as classes do ActualTime,
usando adaptadores de GLPI e banco em memória. A homologação no GLPI deve incluir
dois cronômetros no mesmo chamado, outro técnico, outro chamado e os filtros de
status, além da preservação de chamados solucionados/fechados.

### Atualização do status na tela, sem F5

Na versão **3.2.6-london.3**, iniciar, retomar, pausar ou encerrar também atualiza
o status exibido no chamado aberto. A resposta do cronômetro consulta o estado
**depois** que o AutoStatus processa o evento e inclui o status gravado, seu rótulo,
ícone e as opções de transição permitidas pelo perfil.

O navegador atualiza o seletor Select2, o ícone do painel e o campo de status em
modo de leitura, apenas quando o chamado aberto corresponde à tarefa controlada.
A página permanece aberta: assuntos, descrições e outros textos ainda não salvos
são preservados. Uma escolha manual de status ainda não salva também é preservada.
Essa atualização automática não marca o formulário como alterado nem abre diálogos
de mudança de status. A sincronização entre abas aproveita as consultas existentes
dos cronômetros e continua descartando respostas anteriores a uma ação.

Este ajuste precisa atualizar somente o **ActualTime**; o AutoStatus 1.4.0 já
instalado continua sendo o responsável pelas regras de status.

O teste de navegador usa Playwright, jQuery e Select2, com PHP e os adaptadores de
GLPI do repositório. É possível usar o código real de rastreamento de alterações
do GLPI 10.0.26 informando a pasta do core:

```bash
ACTUALTIME_STATUS_GLPI_CORE=/caminho/glpi node tests/regression/concurrent.browser.cjs
```

Os cenários incluem início/pausa/retomada sem recarregar, seletor e status de leitura,
preservação de rascunhos e de escolhas manuais, respostas atrasadas, outra aba e
cronômetros simultâneos. No espelho, confira o painel do chamado após cada ação.

### Tarefas e acompanhamentos, sem F5

A versão **3.2.6-london.4** também sincroniza o painel após concluir/reabrir uma
tarefa pelo controle do GLPI, abrir/fechar seu editor e salvar tarefas ou
acompanhamentos. O navegador consulta o **status efetivamente salvo** depois da
resposta dessas ações. As regras configuradas no AutoStatus continuam determinando
esse valor; abrir um editor ou reabrir uma tarefa não inicia um cronômetro.

A consulta agora identifica o chamado aberto independentemente dos cronômetros.
Assim, um acompanhamento atualiza o painel mesmo quando não existe nenhuma tarefa
ou cronômetro ativo. A leitura exige acesso ao chamado e omite dados de chamados
inexistentes, na lixeira ou sem permissão. A resposta não contém conteúdo de tarefas
ou acompanhamentos.

As atualizações continuam preservando rascunhos, escolhas manuais de status e as
opções permitidas pelo perfil. Respostas anteriores à ação são descartadas. Ações
de outro chamado e requisições que falharam não disparam essa sincronização.
O endpoint de consulta aceita o parâmetro opcional `ticket_id`; clientes antigos
que não o enviam continuam recebendo os dados dos cronômetros normalmente.

Os testes de navegador incluem conclusão e reabertura nativas, abertura/fechamento
do editor, acompanhamentos em chamado sem tarefas, erros de permissão, outro chamado,
instalação em subdiretório e resposta atrasada. O teste PHP também executa o handler
real de acompanhamentos do AutoStatus e verifica o status retornado sem tarefas.
Este ajuste exige atualizar somente o **ActualTime**, mantendo o AutoStatus 1.4.0.

## Funcionalidades já validadas

Em **Modify timers > Apontamentos existentes**, cada período encerrado tem o
botão **Excluir apontamento**. Ele abre uma confirmação com os horários salvos,
o técnico e a duração. **Cancelar** retorna ao editor; **Confirmar exclusão**
remove somente o apontamento escolhido. Para excluir um registro ativo,
pause o cronômetro antes de abrir a confirmação.

A exclusão recalcula o total da tarefa quando **Automatically update the
duration** estiver habilitado, respeitando o arredondamento do GLPI. Excluir o
último período zera esse total automático. Com a opção desligada, a duração
informada manualmente na tarefa é mantida. O estado da tarefa e os demais
apontamentos permanecem iguais, inclusive outro período ativo da mesma tarefa.

O histórico do chamado, problema, mudança ou projeto mantém o ID do apontamento
excluído, a tarefa, o técnico, o início, o término, a duração exata e o autor
da exclusão. O histórico anterior é preservado. A remoção é definitiva; os dados
ficam disponíveis no histórico para conferência.

A confirmação usa o período salvo. Alterações ainda não salvas nos calendários
do editor não são gravadas ao clicar em excluir. Se o período mudar em outra
aba entre a abertura e a confirmação, a exclusão é bloqueada para revisão.

A edição validada na etapa 2 continua disponível: a seção **Apontamentos existentes** permite editar o início
e o término de registros encerrados. Em um cronômetro ativo, somente o início
pode ser corrigido; ele continua contando até ser pausado ou encerrado pelos
controles normais. Salvar a edição preserva o técnico e o estado da tarefa.

O histórico do chamado, problema, mudança ou projeto registra os horários antes
e depois de cada alteração e seu autor. O indicador de modificação e o primeiro
registro original do ActualTime são mantidos. Não são criadas tabelas ou colunas.

A criação manual validada na etapa 1 continua disponível, inclusive em tarefas
salvas sem apontamentos. O intervalo é salvo encerrado e aparece nos totais
nativos do ActualTime.

Todos os campos de data e hora usam o calendário padrão do
GLPI, pelo mesmo método Html::showDateTimeField() do ActualTime 3.2.0. Os valores
continuam sendo validados no servidor, com precisão de segundos e no mesmo fuso
usado pelo GLPI. A saída HTML usa o PHP para escapar texto e atributos, mantendo
a correção que evita a interrupção após “Data inicial” no GLPI 10.

O tempo é atribuído ao técnico responsável pela tarefa. Se não houver técnico
individual (ou em tarefas de projeto), é atribuído ao usuário que faz o lançamento.
O nome aparece no formulário de inclusão; editar um registro existente mantém
seu técnico original. O lançamento manual não conclui a tarefa nem inicia um
cronômetro.

A permissão **Modify timers** continua necessária. Também são verificados o
acesso à tarefa (inclusive privada) e ao item pai, a entidade, a existência do item
e seu estado. Chamados/problemas/mudanças solucionados ou fechados, projetos
excluídos e tarefas de projeto finalizadas mantêm o bloqueio.

São rejeitados datas inválidas, término igual/anterior ao início, término futuro
e duplicatas na criação manual. A criação não permite sobreposição na mesma
tarefa. Na edição, antecipar o início segue a substituição descrita acima;
demais sobreposições continuam bloqueadas. Intervalos adjacentes são permitidos.
O início de um cronômetro ativo não pode ficar no futuro.

O lote de edição inteiro é validado antes de gravar: um erro impede todas as
alterações daquele envio. As datas propostas permanecem preenchidas após um
erro de validação. Se outra aba tiver modificado um registro que você tentou
alterar, a gravação é bloqueada e os horários mais recentes são exibidos para
conferência. Registros não alterados no formulário não sobrescrevem outras abas.

A duração real tem precisão de segundos. A duração da tarefa só é atualizada se
**Automatically update the duration** estiver habilitado; nesse caso, respeita o
arredondamento do GLPI. Exemplo: um apontamento de 7 minutos continua com 7
minutos reais e pode arredondar a duração da tarefa para 15 minutos.

A criação, a edição e a exclusão usam transações nas tabelas InnoDB do GLPI.
Falhas de gravação revertem apontamentos, histórico e totais. Criação, edição,
exclusão e controles
nativos de iniciar/pausar/encerrar compartilham o bloqueio da linha da tarefa,
para que usem os horários atuais ao calcular o tempo. O total nativo inclui o
tempo já transcorrido do cronômetro ativo, quando houver um.

## Atualização no espelho

1. Faça backup do banco e da pasta atual do ActualTime no **espelho**.
2. Confirme GLPI 10.0.x e a versão London já instalada; interrompa os testes com cronômetros
   ativos antes de substituir os arquivos.
3. Após mesclar a PR desta etapa em **london/glpi10**, atualize essa branch pelo
   LondonPluginManager. Para testar antes da mesclagem, selecione a branch
   **london/ajuste-sobreposicao-inicio**. Use a mesma pasta **actualtime**.
4. Atualize o código e execute a atualização do plugin oferecida pelo GLPI.
   **Não desinstale**: a desinstalação original elimina a tabela de apontamentos.
5. Limpe o cache do GLPI, confirme **3.2.6-london.1** e use **Ctrl+F5** para
   carregar o JavaScript atualizado.
6. Verifique a permissão de perfil em **ActualTime > Modify timers**, por tipo
   de tarefa. Atualizar o código não concede direitos novos automaticamente.

Esta etapa não cria tabelas/colunas nem move os apontamentos existentes. Não
atualize esta instalação diretamente para a branch **develop** do projeto original
ou pelo Marketplace: isso pode substituir as alterações da London. Atualizações
oficiais serão revisadas e incorporadas na branch de manutenção GLPI 10.

## Roteiro de homologação

Use um dia passado e um chamado aberto com uma tarefa salva:

| Teste | Resultado esperado |
|---|---|
| A 08:00–08:30 e B 08:30–09:00; antecipar B para 08:15 | A termina 08:15; B vai de 08:15 a 09:00; total de 1 h |
| Na mesma situação inicial, antecipar B para 08:00 | A excluído; ID de B mantido; B 08:00–09:00 |
| A 07:00–08:00, B 08:00–08:30 e C 08:30–09:00; antecipar C para 07:30 | A termina 07:30, B excluído e C 07:30–09:00 |
| Antecipar um cronômetro ativo sobre período anterior encerrado | Ajuste/exclusão anterior sem pausar o ativo |
| Conferir histórico após salvar | Autor, datas e apontamento que causou a substituição registrados |
| Outra tarefa ativa durante a substituição | Continua contando sem alteração |
| Com dois cronômetros ativos, abrir/recarregar estatísticas do chamado | Soma da coluna ActualTime por técnico igual ao total ActualTime do chamado |
| Trocar responsável de tarefa com períodos já gravados | Tempo antigo continua com o técnico do apontamento |
| Nome de técnico com aspas | Estatísticas carregam normalmente e mostram o nome como texto |
| Reports: chamado fechado com dois períodos na mesma tarefa | Duração da tarefa contada uma vez; tempo real soma os períodos |
| Reports: chamado com dois solicitantes | Uma linha por chamado no total; uma contribuição por solicitante no modo correspondente |
| Reports: selecionar entidade e período de fechamento | Apenas os chamados dessa seleção aparecem |
| Reports: tarefa sem apontamentos e duração zero | Tempo real zero, sem erro de divisão |
| Criar, editar e excluir período passado enquanto outra tarefa conta | Total corrigido e histórico preservado; outro cronômetro continua |
| Iniciar tarefa A e depois tarefa B, com o mesmo técnico | Ambas ativas; dois contadores e dois itens no aviso |
| Pausar A, aguardar e conferir B | A permanece parada; B continua contando |
| Retomar A | Abre novo período em A; B continua sem alteração |
| Encerrar A | Somente A termina; B continua ativa e sua tarefa permanece aberta |
| Recarregar ou acessar outra página do GLPI | B continua ativa e o aviso abre a tarefa correta |
| Pausar B em outra aba | A primeira aba atualiza o contador e os controles de B |
| Iniciar a mesma tarefa em duas abas | Um único cronômetro ativo para ela |
| Desativar somente o aviso de cronômetro nas configurações | Contadores nas tarefas continuam funcionando |
| Tarefas de tipos diferentes com IDs iguais | Tempos, botões e janelas associados ao tipo correto |
| Técnico sem atualização da tarefa ou sem acesso ao item pai | Controle bloqueado, inclusive por requisição direta |
| Gestor trocar o técnico da tarefa A enquanto A e B estão ativas | Período antigo de A pausado; B continua; técnico anterior perde o controle de A |
| Conferir regras de status ao encerrar uma entre várias tarefas | Comportamento esperado das regras instaladas, sem concluir as outras tarefas |
| Criar períodos isolados de 08:00–08:30 e 08:30–08:45 | Total real de 45 min |
| Clicar em excluir no primeiro período e cancelar | Dois registros e total de 45 min permanecem |
| Repetir e confirmar a exclusão do primeiro período | Restam 15 min; segundo registro e estado da tarefa preservados |
| Conferir histórico do chamado | Autor e dados completos do período excluído registrados |
| Excluir o último período | Lista vazia e duração automática igual a zero; permite novo lançamento |
| Tentar excluir registro ativo | Orienta pausar primeiro; depois da pausa, permite excluir |
| Confirmar exclusão em uma aba depois de editar o mesmo registro em outra | Bloqueia exclusão e pede revisão dos valores atuais |
| Excluir período passado enquanto outro está ativo | Cronômetro ativo continua; total segue o cálculo nativo |
| Apontamento isolado de 08:00–08:30; alterar início para 07:45 | Mesmo registro com 45 min e técnico preservado |
| Nesse registro, alterar término para 08:45 | Mesmo registro com 1 h e total recalculado, se habilitado |
| Abrir novamente Modify timers e conferir histórico do chamado | Horários salvos; histórico com autor e valores antes/depois |
| Iniciar um cronômetro e corrigir o início para 10 min antes | Continua ativo; total considera o novo início após recarregar |
| Pausar, retomar e encerrar esse cronômetro | Tempos calculados a partir do início corrigido; controles funcionam |
| Editar com término anterior ao início, data futura ou sobreposição fora da regra de recuo | Erro; nenhuma alteração do lote é salva |
| Abrir editor em duas abas; salvar uma mudança diferente em cada | Segunda mudança é bloqueada; mostra os valores mais recentes |
| Tarefa sem apontamentos; abrir Modify timers | Formulário de criação disponível |
| Adicionar 08:00–08:30 | Registro de 30 min, técnico correto e histórico com autor |
| Adicionar 08:30–08:45 | Segundo registro; total real de 45 min |
| Reenviar 08:00–08:30 | Bloqueio; total continua 45 min |
| Adicionar 08:15–08:35 | Bloqueio de sobreposição |
| Término anterior/igual ao início, campo vazio, data futura | Erro e nenhum novo registro; dados preservados |
| Atualização automática ligada/desligada | Respeitar configuração e arredondamento |
| Tarefa atribuída a outro técnico e tarefa sem técnico | Atribuição conforme nome exibido |
| Perfil sem Modify timers; tarefa privada sem acesso; outra entidade | Acesso negado, inclusive por URL direta |
| Chamado solucionado/fechado; item excluído | Acesso negado |
| Tarefa com cronômetro ativo e período passado sem sobreposição | Adicionar histórico sem parar o cronômetro |
| Pausar/retomar/encerrar um cronômetro normalmente | Comportamento original preservado |
| Envio apontando registro de outra tarefa | Bloqueio sem alterar o registro |

Repetir um lançamento em Problema, Mudança e Projeto, quando esses tipos forem
usados. Conferir também os totais/estatísticas e os plugins de processos no
espelho: os testes automatizados isolados não substituem essa homologação.

## Próximas etapas (uma entrega por vez)

1. **Concluída:** criar apontamento manual encerrado.
2. **Concluída:** alterar início e término, incluindo início de apontamento ativo.
3. **Concluída:** excluir um apontamento com confirmação e auditoria.
4. **Concluída:** liberar tarefas simultâneas para o mesmo usuário.
5. **Concluída na etapa 4:** contadores, lembretes e controles independentes.
6. **Atual:** revisão de totais, permissões e integrações no conjunto.

## Verificação automatizada

Executar na raiz do plugin com PHP 8.1 ou posterior:

    php tests/regression/manualtimer.php
    php tests/regression/edit-timers.php
    php tests/regression/delete-timers.php
    php tests/regression/concurrent-timers.php
    php tests/regression/statistics.php
    php tests/regression/overlap-edit.php
    python tests/regression/reports.sql.py

Para testar com a classe Html real do GLPI 10.0.26, informe a raiz de uma cópia
desse código. O teste importa somente src/Html.php; não inicia o GLPI nem acessa
o banco real:

    ACTUALTIME_GLPI_CORE=/caminho/glpi-10.0.26 php tests/regression/manualtimer.php
    ACTUALTIME_GLPI_CORE=/caminho/glpi-10.0.26 php tests/regression/edit-timers.php
    ACTUALTIME_GLPI_CORE=/caminho/glpi-10.0.26 php tests/regression/delete-timers.php
    ACTUALTIME_GLPI_CORE=/caminho/glpi-10.0.26 php tests/regression/concurrent-timers.php
    ACTUALTIME_GLPI_CORE=/caminho/glpi-10.0.26 php tests/regression/statistics.php
    ACTUALTIME_GLPI_CORE=/caminho/glpi-10.0.26 php tests/regression/overlap-edit.php

Com Playwright/Chromium, jQuery 3.6.0 e Flatpickr 4.6.13 disponíveis, o teste de
navegador usa a classe Html e os helpers JavaScript do GLPI 10.0.26. Abre o
calendário original em um iframe, valida datas da criação e grava edições de
apontamentos encerrados e ativos pelos métodos PHP reais do plugin. Também
exercita o controle de envio único do GLPI, a confirmação, o cancelamento e a
exclusão, inclusive com campos de edição inválidos que ainda não foram salvos:

    ACTUALTIME_GLPI_CORE=/caminho/glpi-10.0.26 node tests/regression/calendar.browser.cjs
    ACTUALTIME_GLPI_CORE=/caminho/glpi-10.0.26 node tests/regression/concurrent.browser.cjs
    ACTUALTIME_GLPI_CORE=/caminho/glpi-10.0.26 node tests/regression/statistics.browser.cjs

A cópia do GLPI deve conter src/Html.php, js/common.js e
js/flatpickr_buttons_plugin.js. O PHP usado no teste precisa da extensão intl.
O teste de simultaneidade usa os métodos PHP e o JavaScript reais do plugin,
duas abas e um adaptador para o ciclo de abertura/fechamento do Bootstrap.
O teste SQL executa as consultas geradas pelo PHP em um banco SQLite em memória,
com vários períodos, solicitantes, técnicos e entidades. Valida os cálculos
relacionais; a execução completa no Reports/MySQL continua na homologação.
PHP_BINARY pode indicar o executável PHP nos testes Python e de navegador.

Os testes executam o código do plugin com adaptadores em memória para os serviços
do GLPI. Cobrem datas, autorização, persistência, arredondamento, duplicatas,
edição ativa, exclusão confirmada, tarefas simultâneas, conflitos entre abas, controles nativos,
rollback e formulário.
O ambiente real com GLPI/MySQL, concorrência entre
requisições e interação visual precisam ser validados no espelho.

