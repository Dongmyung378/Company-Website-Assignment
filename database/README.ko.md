<p align="right"><a href="README.md">English</a> | <strong>한국어</strong></p>

# 데이터베이스 설정 및 마이그레이션

[프로젝트 소개](../README.ko.md)

## 계정과 실행 조건

지원 중인 MySQL의 보안 패치 버전과 MySQLi가 활성화된 PHP가 필요합니다. 검증 버전은 MySQL 8.4.13 LTS입니다. 웹 루트는 `public/`만 지정하며 모든 설정 진입점은 HTTP 실행을 거부합니다.

`.env.example`을 저장소 루트의 `.env`로 복사합니다. 로컬 과제 실행에서는 비밀번호가 설정된 기존 MySQL 관리자를 `DB_SETUP_USER` 및 `DB_SETUP_PASSWORD`로 지정할 수 있습니다. 설정 계정에는 DB와 스키마, 트리거와 프로시저를 만들고 실행 계정을 생성 및 권한 부여할 수 있는 권한이 필요합니다. **이 계정을 웹 실행용 `DB_USER`로 사용하지 마세요.** 실제 배포에서는 관리자 정보를 웹 프로세스에 유지하지 않고 별도로 관리하는 마이그레이션 자격증명을 사용해야 합니다.

`DB_USER`에는 `company_app`처럼 root가 아닌 새 실행 계정을 지정하고 `DB_PASSWORD`에는 설정 계정과 다른 충분히 긴 무작위 비밀번호를 설정합니다. MySQL 포트에 맞춰 `DB_PORT`를 지정합니다. 예시는 3307이며 일반적인 설치는 3306일 수 있습니다. README의 로컬 실행 명령은 `APP_ORIGIN=http://127.0.0.1:8000`을 사용합니다. 프로세스 환경 변수가 `.env`보다 우선합니다.

## 새로 설치

저장소 루트에서 실행합니다.

~~~sh
php scripts/setup.php
php scripts/create-runtime-user.php
~~~

첫 명령은 스키마를 만들고 가상 직원 197명의 데이터를 한 번만 가져옵니다. 직원 추가 트리거 두 개와 삭제 프로시저도 준비합니다. 각 계정에 서로 다른 무작위 임시 비밀번호를 부여하고 최초 로그인 시 변경을 요구합니다. CLI에는 비밀번호가 아닌 **파일 경로**만 표시합니다. 로컬 `var/private/credentials-*.json`의 `accounts` 배열에서 `employee_id`와 `temporary_password`를 확인할 수 있습니다. 비공개로 보관하고 최초 전달이 끝나면 필요에 따라 삭제하세요. Git에는 포함되지 않습니다.

둘째 명령은 설정된 `DB_PASSWORD`로 로컬 접속용 `DB_USER` 계정을 만듭니다. 이미 존재하는 MySQL 계정은 덮어쓰지 않습니다. 비밀번호를 설정하지 않았다면 `var/private/runtime-db-*.json`에 무작위 값을 발급하므로, 서버 실행 전 비공개 환경 설정에 옮겨 적어야 합니다. `.env`에 지정한 계정명을 그대로 사용합니다. 선택 옵션은 `--user=...`, `--host=127.0.0.1`이며 `localhost`와 `::1`도 허용합니다.

이미 직접 준비한 계정을 이용한다면 MySQL 관리자로 권한을 확인하고 계정 생성 명령을 반복하거나 비밀번호를 초기화하지 마세요. 스키마 설정 및 마이그레이션 명령은 재실행해도 비밀번호, 휴가 잔여량과 신청, 수정한 직원 및 삭제 기록을 유지하고 삭제한 직원을 복원하지 않습니다.

## 기존 데이터베이스 갱신

먼저 백업한 뒤 기존 DB를 가리키는 설정 계정으로 실행합니다.

~~~sh
php scripts/migrate.php
~~~

직원, ID, 급여, 연락처, 휴가 및 삭제 기록은 유지하면서 자동 ID 발급, 직원 변경 버전, 인증 버전, 최초 변경 표시, 로그인 실패 기록을 추가하고 삭제 기록 필드의 길이를 확장합니다. 알려진 이전 비밀번호 `0000`의 해시는 개별 임시 비밀번호로 교체하고 비공개 파일로 제공합니다. 기본값이 아닌 비밀번호 해시는 유지합니다. 이전 방식의 세션은 현재 세션 검사를 통과하지 못합니다.

비밀번호 해시가 NULL인 비활성 계정은 그대로 둡니다. 최초 접근 권한이 필요한 비활성 계정은 명시적으로 발급할 수 있습니다.

~~~sh
php scripts/migrate.php --provision-inactive
~~~

이 옵션은 비활성 계정만 대상으로 하며 이미 설정한 비밀번호를 바꾸지 않습니다. 설정 명령끼리는 DB 잠금으로 동시 실행을 조정합니다. MySQL의 DDL은 자체적으로 커밋되므로 스키마 변경 전체가 하나의 트랜잭션으로 롤백되는 것은 아닙니다. 실패 시 비공개 CLI 로그로 원인을 확인하고 백업한 DB를 기준으로 마이그레이션을 완료해야 합니다.

## 웹 실행 계정의 권한

| 대상 | 권한 |
| --- | --- |
| `department`, `office`, `position` | SELECT |
| `employees`, `emergency_table`, `holiday_balance`, `holiday_requests`, `login` | SELECT, INSERT, UPDATE |
| `delete_employee_logs` | 조회 및 명시적 만료 기록 정리를 위한 SELECT, DELETE |
| `login_attempts` | SELECT, INSERT, DELETE |
| `delete_employee_with_log` | EXECUTE |

스키마 변경, DB 계정 관리, FILE 및 GRANT OPTION 권한은 부여하지 않습니다. 직원 삭제는 트랜잭션을 관리하는 definer 방식 프로시저로 처리합니다. 프로시저 소유자는 관리되는 스키마 계정이어야 하며 사용 중에 해당 계정을 삭제하면 안 됩니다.

## 데이터와 초기 과제 이력

`database/seeds/employees.csv`는 변경하지 않았습니다. CSV 구분자와 인용 문자, 빈 이스케이프 인자를 명시하므로 PHP 8.5에서도 가져오기 경고가 발생하지 않습니다. `schema_migrations`에 스키마 및 보안 변경과 최초 데이터 반영 여부를 기록합니다. 현재 구현은 `schema.php`, `objects.php`, `setup_support.php`에 있습니다.

이전 `database/setup/*.php` 파일 14개는 안전한 설정 및 마이그레이션으로 연결하는 CLI 호환 진입점으로 유지합니다. 비밀번호를 초기화하거나 시드를 중복 삽입하지 않습니다. 예전 순차 실행 대신 현재의 단일 설정 명령을 이용하세요. 선택 과제인 상거래 및 배송 스키마는 `extra_tables.php`와 `--with-extensions`로 유지하며 인사 관리 UI에서는 사용하지 않습니다.

`.env`와 `var/`는 실행 계정만 읽을 수 있도록 접근 권한을 제한하세요. POSIX에서는 비공개 파일 권한을 적용하고 공유 Windows 환경에서는 ACL도 직접 제한해야 합니다. 비밀번호 파일, DB 및 로그를 포트폴리오 자료로 올리지 마세요. Git 이력은 다시 쓰지 않고 초기 코드를 보존합니다.
