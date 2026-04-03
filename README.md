> Build 3.8.2.1: queue operational focus, next-action hints in the team queue, and queue-level automation/notification visibility.

# Help Desk 3.8.2

- limpeza adicional de termos visíveis em inglês no pt_br
- fallback localizado para copiar resposta pronta
- remoção de arquivo de backup residual do pacote

# MundoPHPBB Help Desk

Extensão genérica de Help Desk para phpBB 3.3+, com foco em operação de tickets, fila da equipe, SLA, alertas internos, automação de fluxo e leitura operacional da carga da equipe.

**Pacote documental desta build:** 4.9.59
**Versão declarada no `composer.json`:** 3.7.9.2
**Namespace / caminho:** `mundophpbb/helpdesk`

**Ajuste desta build:** revisão final controlada da base 3.7.9.1, com limpeza de termos em inglês no `pt_br` e exibição localizada dos perfis do Help Desk no ACP.

---

## 1. Visão geral

A extensão transforma fóruns selecionados em áreas de atendimento, adicionando metadados e ferramentas operacionais para acompanhar tickets com mais controle.

Principais recursos:

- status do ticket
- prioridade
- categoria
- departamento
- responsável
- histórico de atividade
- ações em massa
- automações de fluxo
- regras de SLA
- regras de fila por departamento, prioridade e responsável
- alertas internos da equipe
- painel operacional da equipe
- relatórios gerenciais e operacionais
- sugestões e prévias de redistribuição de carga
- suporte multilíngue (`pt_br` e `en`)

---

## 2. Requisitos

- phpBB `3.3.0` ou superior
- PHP `7.4` ou superior
- extensão instalada em `ext/mundophpbb/helpdesk`

---

## 3. Instalação

1. Envie a pasta da extensão para:
   `ext/mundophpbb/helpdesk`
2. No ACP, acesse:
   `Personalizar -> Gerenciar extensões`
3. Ative a extensão **Help Desk**.
4. Acesse o módulo do ACP da extensão para configurar:
   - fóruns habilitados
   - listas de status
   - prioridades
   - categorias
   - departamentos
   - automações
   - SLA
   - alertas
   - painel da equipe
5. Purgue o cache do phpBB após instalar ou substituir arquivos.

---

## 4. Atualização

1. Desative a extensão, se necessário no seu fluxo de manutenção.
2. Substitua os arquivos da extensão pelos arquivos novos.
3. Reative ou atualize a extensão no ACP.
4. Purgue o cache.
5. Revise as configurações no ACP e valide a fila da equipe.

### Observação importante

Esta linha de desenvolvimento já recebeu ajustes para remoção limpa com **Delete Data**, incluindo limpeza de módulos ACP, permissões e configurações criadas pelas migrations.

---

## 5. Remoção

Se quiser remover completamente a extensão:

1. Desative a extensão.
2. Use a opção **Delete Data**.
3. Confirme que:
   - o módulo ACP foi removido
   - as permissões `m_helpdesk_*` não ficaram órfãs
   - a extensão pode ser reinstalada em ciclo limpo

---

## 6. Permissões

A extensão agora trabalha com um bloco de permissões mais completo:

### 6.1. Administrativas

- `a_helpdesk_manage` — pode administrar configurações e permissões do Help Desk

### 6.2. Operacionais da equipe

- `m_helpdesk_manage` — pode gerenciar status e departamento
- `m_helpdesk_assign` — pode atribuir tickets
- `m_helpdesk_bulk` — pode usar ações em massa
- `m_helpdesk_queue` — pode acessar a fila da equipe

### 6.3. Por fórum

- `f_helpdesk_view` — pode ver o contexto do Help Desk e a lista de tickets próprios
- `f_helpdesk_ticket` — pode abrir e editar tickets do Help Desk nos fóruns habilitados

### 6.4. Perfis prontos

A migration cria roles dedicadas para acelerar a distribuição:

- `Help Desk Administrator`
- `Help Desk Supervisor`
- `Help Desk Agent`
- `Help Desk Auditor`
- `Help Desk Customer`
- `Help Desk Read Only`

Recomenda-se revisar os grupos administrativos, de suporte e os fóruns habilitados após a atualização.

---

## 7. Configuração no ACP

A configuração foi organizada para reduzir o risco de perda de contexto e facilitar manutenção.

### 7.1. Geral

Use esta área para:

- ativar ou desativar o Help Desk
- informar os fóruns habilitados
- definir prefixo sugerido
- escolher status padrão
- ativar ou desativar campos como prioridade, categoria, departamento e responsável
- ativar painel da equipe, alertas e SLA
- definir janelas de SLA, stale e very old

### 7.2. Fluxo e automações

Permite configurar:

- status após resposta da equipe
- status após resposta do usuário
- autoatribuição ao responder
- trancar tópico ao fechar
- destrancar ao reabrir
- status operacionais ao atribuir, desatribuir, mudar departamento ou aumentar prioridade

Também há suporte a regras mais específicas por:

- departamento
- prioridade
- departamento + prioridade

### 7.3. Classificação

Permite manter listas compartilhadas entre idiomas para:

- categorias
- departamentos
- prioridades
- status

### 7.4. Regras de SLA e fila

A extensão aceita regras específicas para:

- departamento
- prioridade
- departamento + prioridade
- responsável

Com isso, cada combinação pode ter:

- horas de SLA
- horas para stale
- horas para very old
- reforço de fila
- limiar de alerta

### 7.5. Notificações por e-mail

Há suporte para notificações leves por e-mail, com opções para:

- autor do ticket
- responsável
- resposta do usuário
- prefixo de assunto

---

## 8. Fluxo no tópico

Nos tópicos habilitados, o Help Desk passa a controlar campos como:

- status
- prioridade
- categoria
- departamento
- responsável
- histórico de mudanças
- histórico de atividade

A equipe pode atualizar o ticket manualmente e a extensão registra as ações em log próprio.

---

## 9. Fila da equipe

O painel da equipe é a área operacional principal da extensão.

### 9.1. Abas principais

O painel foi estruturado em abas para manter a navegação utilizável mesmo com muitos recursos:

- **Overview**
- **Queue**
- **Personal**
- **Triage**
- **Balance**
- **Reports**
- **Alerts**
- **History**

### 9.2. Escopos rápidos

A fila da equipe oferece escopos operacionais para acelerar a triagem, como por exemplo:

- todos
- abertos
- aguardando
- sem responsável
- fechados
- dentro do SLA
- atrasados
- sem resposta recente
- primeira resposta pendente
- muito antigos
- por responsável
- aguardando equipe
- reabertos
- sobrecarga
- backlog envelhecendo

### 9.3. Recursos operacionais do painel

Entre os recursos já integrados ao painel estão:

- visão geral da operação
- saúde operacional da fila
- distribuição de carga por responsável
- cobertura e pressão por departamento
- backlog por fórum
- hotspots fórum / departamento
- envelhecimento do backlog
- produtividade e resposta da equipe
- resumo executivo
- plano de ação do turno
- alertas recentes
- histórico operacional
- filtros e atalhos salvos
- sugestões de redistribuição
- prévia de redistribuição balanceada
- prévias focadas em sobrecarga, prioridade alta, prioridade crítica, departamento e responsável

---

## 10. Redistribuição de carga

A extensão já possui uma camada de redistribuição operacional para aliviar a equipe.

Recursos disponíveis:

- sugestão individual
- aplicação em lote
- seleção inteligente
- plano balanceado
- simulação antes de aplicar
- foco em sobrecarga
- foco em prioridade alta
- foco em prioridade crítica
- foco por departamento
- foco por responsável

Esse bloco foi pensado para ajudar a operação sem obrigar leitura manual da fila inteira.

---

## 11. Relatórios

A aba **Reports** consolida leituras operacionais e gerenciais, incluindo:

- resumo executivo
- plano de ação do turno
- backlog por fórum
- hotspots fórum / departamento
- envelhecimento do backlog
- produtividade e resposta
- resposta por responsável
- capacidade por carga
- distribuição por status
- distribuição por departamento
- distribuição por prioridade
- distribuição por responsável

---

## 12. Exemplo de fluxo recomendado

### Atendimento básico

1. usuário cria o tópico em fórum habilitado
2. ticket entra como aberto
3. equipe faz triagem
4. responsável é definido
5. prioridade e departamento são ajustados
6. ticket segue acompanhamento
7. ao resolver, status muda para resolvido ou fechado
8. se configurado, o tópico é trancado automaticamente

### Fluxo com automação

1. ticket sem responsável recebe resposta da equipe
2. extensão pode atribuir automaticamente ao atendente
3. status pode mudar para aguardando resposta ou em progresso
4. se o usuário responder, o status pode voltar ao estágio configurado
5. se o ticket envelhecer, ele passa a aparecer em escopos de SLA, stale ou very old

---

## 13. Boas práticas de implantação

- habilite primeiro em um ou poucos fóruns de teste
- revise listas de status, prioridade, categoria e departamento antes de abrir para produção
- confirme permissões dos moderadores
- valide o painel da equipe com mais de um usuário staff
- purgue o cache sempre após substituir arquivos
- teste ciclo completo: abrir ticket, responder, atribuir, alterar prioridade, fechar e remover com Delete Data

---

## 14. Estrutura útil do pacote

Arquivos importantes da extensão:

- `composer.json`
- `ext.php`
- `config/permissions.yml`
- `config/routing.yml`
- `config/services.yml`
- `controller/queue_controller.php`
- `event/listener.php`
- `acp/main_module.php`
- `styles/all/template/helpdesk_queue_body.html`
- `language/pt_br/common.php`
- `language/en/common.php`

---

## 15. Observações finais

- a extensão foi evoluída em etapas, com foco em manter compatibilidade com o core do phpBB
- boa parte das leituras mais avançadas do painel da equipe é baseada no estado operacional atual da fila, sem fingir histórico persistido que a instalação não tenha
- para publicação em tópico de suporte, apresentação ou repositório, use também o arquivo em BBCode incluído neste pacote

---

## 16. Arquivo adicional neste pacote

Além deste `README.md`, este pacote inclui:

- `docs/MANUAL_PHPBB_BBCODE_PT_BR.txt`

Esse segundo arquivo está pronto para copiar e colar em um tópico do phpBB, mantendo a formatação em BBCode.


## 3.3.0

- Added an initial setup assistant in ACP > Help Desk > Permissions to select tracked forums and apply the main Help Desk roles to existing groups in one step.


## 3.5.0
- ACP operational overview with status distribution and recent activity snapshot
- Improved ACP table styling for permission and overview screens


## 3.7.9.3
- limpeza adicional de termos em inglês no pt_br
- rótulos e diagnósticos mais consistentes no ACP
- aria-labels localizados no painel da equipe e no ACP

## Build 3.8.1
- Added visible automation and behavioral notification snapshots in viewtopic, My Tickets and ACP.
- No schema changes and no new migrations.


## 3.8.3 final release polish

This build focuses on release readiness rather than schema changes.

Included in this package:
- ACP final release checklist panel
- final validation docs in PT-BR and EN
- no new migration or schema change
- intended as the final stabilization pass on top of 3.8.2.1
