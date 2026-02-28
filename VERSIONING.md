# Versioning and Restore Points

## Tag convention
- `v0.1`, `v0.2`, `v0.3`:
  - Stable milestones validated in Zabbix UI.
- `hotfix-YYYYMMDD-N`:
  - Urgent production fix.
  - Example: `hotfix-20260228-1`.

## Recommended commit style
- `feat: ...` new feature
- `fix: ...` bug fix
- `refactor: ...` internal cleanup
- `chore: ...` non-functional maintenance
- `hotfix: ...` urgent correction

## Baseline flow
1. Commit stable state:
   - `git add .`
   - `git commit -m "feat: dynamic sla enterprise baseline"`
2. Create release tag:
   - `git tag -a v0.1 -m "v0.1 baseline stable"`

## Hotfix flow
1. Commit hotfix:
   - `git add .`
   - `git commit -m "hotfix: short description"`
2. Tag hotfix:
   - `git tag -a hotfix-YYYYMMDD-N -m "hotfix details"`

## Fast rollback
- Show tags:
  - `git tag --list`
- Compare two versions:
  - `git diff v0.1..v0.2`
- Restore working tree to a tag (detached for inspection):
  - `git checkout v0.1`
- Return to main branch:
  - `git checkout main`
- Hard restore only when explicitly desired:
  - `git reset --hard v0.1`
