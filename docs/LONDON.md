# ActualTime London — etapa 2

Base: **ActualTime 3.2.0 / GLPI 10.0.x**. Versão desta entrega: **3.2.2-london.1**.
Projeto original e créditos: [TICGAL/ActualTime](https://github.com/ticgal/actualtime).
Mantida a licença AGPL v3 ou posterior.

## Entrega atual

Em **Modify timers**, a seção **Apontamentos existentes** permite editar o início
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

São rejeitados datas inválidas, término igual/anterior ao início, término futuro,
duplicatas e sobreposição com outro apontamento da **mesma tarefa**, inclusive
com um cronômetro ativo. Intervalos adjacentes são permitidos. O início de um
cronômetro ativo não pode ficar no futuro.

O lote de edição inteiro é validado antes de gravar: um erro impede todas as
alterações daquele envio. As datas propostas permanecem preenchidas após um
erro de validação. Se outra aba tiver modificado um registro que você tentou
alterar, a gravação é bloqueada e os horários mais recentes são exibidos para
conferência. Registros não alterados no formulário não sobrescrevem outras abas.

A duração real tem precisão de segundos. A duração da tarefa só é atualizada se
**Automatically update the duration** estiver habilitado; nesse caso, respeita o
arredondamento do GLPI. Exemplo: um apontamento de 7 minutos continua com 7
minutos reais e pode arredondar a duração da tarefa para 15 minutos.

A criação e a edição usam transações nas tabelas InnoDB do GLPI. Falhas de
gravação revertem apontamentos, histórico e totais. Criação, edição e controles
nativos de iniciar/pausar/encerrar compartilham o bloqueio da linha da tarefa,
para que usem os horários atuais ao calcular o tempo. O total nativo inclui o
tempo já transcorrido do cronômetro ativo, quando houver um.

## Atualização no espelho

1. Faça backup do banco e da pasta atual do ActualTime no **espelho**.
2. Confirme GLPI 10.0.x e a versão London já instalada; interrompa os testes com cronômetros
   ativos antes de substituir os arquivos.
3. Após mesclar a PR desta etapa em **london/glpi10**, atualize essa branch pelo
   LondonPluginManager. Para testar antes da mesclagem, selecione a branch
   **london/etapa-2-editar-apontamentos**. Use a mesma pasta **actualtime**.
4. Atualize o código e execute a atualização do plugin oferecida pelo GLPI.
   **Não desinstale**: a desinstalação original elimina a tabela de apontamentos.
5. Limpe o cache do GLPI e confirme a versão **3.2.2-london.1**.
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
| Apontamento isolado de 08:00–08:30; alterar início para 07:45 | Mesmo registro com 45 min e técnico preservado |
| Nesse registro, alterar término para 08:45 | Mesmo registro com 1 h e total recalculado, se habilitado |
| Abrir novamente Modify timers e conferir histórico do chamado | Horários salvos; histórico com autor e valores antes/depois |
| Iniciar um cronômetro e corrigir o início para 10 min antes | Continua ativo; total considera o novo início após recarregar |
| Pausar, retomar e encerrar esse cronômetro | Tempos calculados a partir do início corrigido; controles funcionam |
| Editar com término anterior ao início, data futura ou sobreposição | Erro; nenhuma alteração do lote é salva |
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
2. **Atual:** alterar início e término, incluindo início de apontamento ativo.
3. Excluir um apontamento com confirmação e auditoria.
4. Liberar tarefas simultâneas para o mesmo usuário.
5. Adaptar contadores, lembretes e controles independentes.
6. Revisar totais, permissões e integrações no conjunto.

## Verificação automatizada

Executar na raiz do plugin com PHP 8.1 ou posterior:

    php tests/regression/manualtimer.php
    php tests/regression/edit-timers.php

Para testar com a classe Html real do GLPI 10.0.26, informe a raiz de uma cópia
desse código. O teste importa somente src/Html.php; não inicia o GLPI nem acessa
o banco real:

    ACTUALTIME_GLPI_CORE=/caminho/glpi-10.0.26 php tests/regression/manualtimer.php
    ACTUALTIME_GLPI_CORE=/caminho/glpi-10.0.26 php tests/regression/edit-timers.php

Com Playwright/Chromium, jQuery 3.6.0 e Flatpickr 4.6.13 disponíveis, o teste de
navegador usa a classe Html e os helpers JavaScript do GLPI 10.0.26. Abre o
calendário original em um iframe, valida datas da criação e grava edições de
apontamentos encerrados e ativos pelos métodos PHP reais do plugin:

    ACTUALTIME_GLPI_CORE=/caminho/glpi-10.0.26 node tests/regression/calendar.browser.cjs

A cópia do GLPI deve conter src/Html.php, js/common.js e
js/flatpickr_buttons_plugin.js. O PHP usado no teste precisa da extensão intl.

Os testes executam o código do plugin com adaptadores em memória para os serviços
do GLPI. Cobrem datas, autorização, persistência, arredondamento, duplicatas,
edição ativa, conflitos entre abas, controles nativos, rollback e formulário.
O ambiente real com GLPI/MySQL, concorrência entre
requisições e interação visual precisam ser validados no espelho.
