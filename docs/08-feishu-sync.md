# Feishu Bitable progress sync

The team-facing progress table lives in a Feishu **多维表格 (Bitable)**. The source of truth is
[`docs/progress.json`](progress.json) in this repository, and
[`scripts/sync_feishu.php`](../scripts/sync_feishu.php) pushes it into the table.

```
docs/progress.json  ──►  scripts/sync_feishu.php  ──►  Feishu Bitable
        ▲                        ▲
        │                        └── run by .github/workflows/feishu-sync.yml
        └── updated by the team (one row per week)
```

## How the automation works

1. A pull request merges to `main` with a change to `docs/progress.json`.
2. The **Feishu progress sync** workflow starts (it also has a manual *Run workflow* button).
3. The script:
   - obtains a `tenant_access_token` from the Feishu Open Platform app;
   - creates any missing table fields (`--ensure-schema`);
   - reads the existing records, indexes them by the `周次` field;
   - creates the new weeks and updates the existing ones (upsert, never duplicates).

The workflow **skips itself with a notice** when the repository secrets are absent, so the pipeline stays
green before the Feishu side is configured.

## One-time setup

### 1. A Feishu tenant is required

Feishu's Open Platform data APIs (Bitable, Drive, tenant info) are only available to apps that belong to
an **enterprise/team tenant**. A personal account ("豆包账号") gets
`21001 The requested scope is blocked by data security policy` for every data API, even after the scope
is granted on the app. Create a free Feishu team (标准版) if the project does not have one yet, then
open https://open.feishu.cn/app with that team identity.

### 2. Create the app and its permissions

1. Open Platform → 创建企业自建应用 (name it e.g. `DineReserve Progress`).
2. 权限管理 → 开通权限 → search and enable:
   - `bitable:app` — 查看、评论、编辑和管理多维表格
   - `drive:drive` — 查看、编辑和管理云空间中所有文件
3. 版本管理与发布 → 创建版本 → 申请发布 (the tenant administrator approves; on a personal team the
   creator is the administrator).
4. 凭证与基础信息 → copy **App ID** and **App Secret**.

### 3. Create the Bitable and share it with the app

1. In Feishu create a 多维表格 named `DineReserve 项目进度` (the script can create the fields, not the base).
2. Open it, `分享` → `添加协作者` → search the app name → give it **可编辑** permission.
3. Copy the tokens out of the URL:
   `https://xxx.feishu.cn/base/<APP_TOKEN>?table=<TABLE_ID>`

### 4. Store the four secrets in GitHub

```bash
gh secret set FEISHU_APP_ID            --body "cli_xxx"
gh secret set FEISHU_APP_SECRET        --body "xxx"
gh secret set FEISHU_BITABLE_APP_TOKEN --body "bascnxxx"
gh secret set FEISHU_BITABLE_TABLE_ID  --body "tblxxx"
```

Then run the workflow once manually (Actions → *Feishu progress sync* → *Run workflow*) or push a change
to `docs/progress.json`.

## Running it locally

```bash
export FEISHU_APP_ID=cli_xxx
export FEISHU_APP_SECRET=xxx
export FEISHU_BITABLE_APP_TOKEN=bascnxxx
export FEISHU_BITABLE_TABLE_ID=tblxxx

php scripts/sync_feishu.php --dry-run --ensure-schema   # preview, writes nothing
php scripts/sync_feishu.php --ensure-schema             # real sync
```

Keep the credentials out of the repository (environment variables or a local `.env` that is git-ignored).

## Updating the progress

1. Edit `docs/progress.json` — one object per week (`week`, `phase`, `task`, `requirement`, `owner`,
   `status`, `planned_start`, `planned_end`, `actual_done`, `progress` 0–1, `artifact`, `link`, `note`).
2. Open a pull request; when it merges, the workflow updates the Bitable.
3. Keep the values honest: `actual_done` is the date the work was really finished (W1–W6 are all
   `2026-11-02`, see [07-delivery-log.md](07-delivery-log.md)).

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `21001 ... blocked by data security policy` on every API | The app belongs to a personal (豆包) account, not an enterprise tenant. See §1. |
| `21001` only on Bitable/Drive | `bitable:app` / `drive:drive` not granted, or the app version is not published. |
| `1254004` / `Forbidden` on records | The Bitable is not shared with the app (`分享 → 添加协作者 → 可编辑`). |
| `missing fields in the Bitable` | Rerun with `--ensure-schema`, or create the fields manually (names must match exactly). |
| Workflow finishes with "skipped" notice | Repository secrets are missing — see §4. |
