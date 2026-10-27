# ActualTime London — etapa 1

Base: **ActualTime 3.2.0 / GLPI 10.0.x**. Versão desta entrega: **3.2.1-london.1**.
Projeto original e créditos: [TICGAL/ActualTime](https://github.com/ticgal/actualtime).
Mantida a licença AGPL v3 ou posterior.

## Entrega atual

O botão **Modify timers** / **Modificar temporizadores** passa a oferecer
**Adicionar apontamento manual**, inclusive quando a tarefa salva ainda não
possui apontamentos. O início e o término são obrigatórios. O intervalo é salvo
encerrado e aparece no histórico e nos totais nativos do ActualTime.

O tempo é atribuído ao técnico responsável pela tarefa. Se não houver técnico
individual (ou em tarefas de projeto), é atribuído ao usuário que faz o lançamento.
O nome aparece no formulário; o autor da inclusão fica no histórico do chamado,
problema, mudança ou projeto. O lançamento não conclui a tarefa nem inicia um
cronômetro.

A permissão **Modify timers** continua necessária. Também são verificados o
acesso à tarefa (inclusive privada) e ao item pai, a entidade, a existência do item
e seu estado. Chamados/problemas/mudanças solucionados ou fechados, projetos
excluídos e tarefas de projeto finalizadas mantêm o bloqueio.

São rejeitados datas inválidas, término igual/anterior ao início, término futuro,
duplicatas e sobreposição com outro apontamento da **mesma tarefa**, inclusive
com um cronômetro ativo. Intervalos adjacentes são permitidos. Em erro, as datas
continuam preenchidas ao retornar ao formulário.

A duração real tem precisão de segundos. A duração da tarefa só é atualizada se
**Automatically update the duration** estiver habilitado; nesse caso, respeita o
arredondamento do GLPI. Exemplo: um apontamento de 7 minutos continua com 7
minutos reais e pode arredondar a duração da tarefa para 15 minutos.

A criação e a atualização da duração usam uma transação nas tabelas InnoDB do
GLPI. Falhas de gravação revertem o apontamento e os totais. O bloqueio da linha
da tarefa evita duplicatas em envios manuais simultâneos.

## Atualização no espelho

1. Faça backup do banco e da pasta atual do ActualTime no **espelho**.
2. Confirme GLPI 10.0.x e ActualTime 3.2.0; interrompa os testes com cronômetros
   ativos antes de substituir os arquivos.
3. Use a branch da etapa 1 indicada no PR, sempre dentro da pasta **actualtime**
   no diretório de plugins utilizado pela sua instalação.
4. Atualize o código e execute a atualização do plugin oferecida pelo GLPI.
   **Não desinstale**: a desinstalação original elimina a tabela de apontamentos.
5. Limpe o cache do GLPI e confirme a versão **3.2.1-london.1**.
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
| Editar término de registro existente | Editor original funcionando; sem acesso a registros de outra tarefa |

Repetir um lançamento em Problema, Mudança e Projeto, quando esses tipos forem
usados. Conferir também os totais/estatísticas e os plugins de processos no
espelho: os testes automatizados isolados não substituem essa homologação.

## Próximas etapas (uma entrega por vez)

1. **Atual:** criar apontamento manual encerrado.
2. Alterar início e término, incluindo início de apontamento ativo.
3. Excluir um apontamento com confirmação e auditoria.
4. Liberar tarefas simultâneas para o mesmo usuário.
5. Adaptar contadores, lembretes e controles independentes.
6. Revisar totais, permissões e integrações no conjunto.

## Verificação automatizada

Executar na raiz do plugin com PHP 8.1 ou posterior:

    php tests/regression/manualtimer.php

Os testes executam o código do plugin com adaptadores em memória para os serviços
do GLPI. Cobrem datas, autorização, persistência, arredondamento, duplicatas,
rollback e formulário. O ambiente real com GLPI/MySQL, concorrência entre
requisições e interação visual precisam ser validados no espelho.
