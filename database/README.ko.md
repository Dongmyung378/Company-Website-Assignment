<p align="right">
  <a href="README.md">English</a> · <strong>한국어</strong>
</p>

# 데이터베이스 설치

[← 프로젝트 소개로 돌아가기](../README.ko.md)

설치 스크립트는 가상의 CSV 데이터를 정규화된 애플리케이션 스키마로 변환합니다. 비어 있는 로컬 데이터베이스를 대상으로 아래 순서대로 한 번씩 실행하세요.

## 연결 설정

연결 정보는 `config/database.php`에서 관리하며 `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` 환경 변수로 덮어쓸 수 있습니다. 기본값은 MySQL 포트 `3307`과 데이터베이스 이름 `company_db`입니다.

## 초기화 순서

리포지토리 루트에서 다음 명령을 나열된 순서대로 실행합니다.

```bash
php database/setup/set_database.php
php database/setup/material_table.php
php database/setup/get_employees_data.php
php database/setup/department_table.php
php database/setup/position_table.php
php database/setup/office_table.php
php database/setup/employee_table.php
php database/setup/emergency_table.php
php database/setup/holiday_tables.php
php database/setup/set_holiday.php
php database/setup/delete_employee_logs.php
php database/setup/login_table.php
php database/setup/set_triggers_and_procedure.php
```

선택형 확장 스키마는 별도로 설치할 수 있습니다.

```bash
php database/setup/extra_tables.php
```

마지막 필수 단계에는 MySQL의 `TRIGGER`와 `CREATE ROUTINE` 권한이 필요합니다.

## 초기 데이터 동작

- 원본 파일: `seeds/employees.csv`
- 모든 인물과 연락처 정보는 가상 데이터입니다.
- 초기 로그인 비밀번호 `0000`은 bcrypt 해시로 저장됩니다.
- 초기 휴가 잔여 일수는 연차 28일, 병가 10일, 개인 휴가 5일입니다.

## 선택형 확장 스키마

`extra_tables.php`는 학습 목적으로 커머스, 결제, 재고, 고객, 배송 테이블을 생성합니다. 현재 인사 운영 UI에서는 이 테이블들을 사용하지 않습니다.

## 로컬 데이터베이스 초기화

설치 과정은 새 데이터베이스를 기준으로 작성되었습니다. 다시 초기화하려면 사용하는 MySQL 관리 도구에서 로컬 `company_db` 데이터베이스를 삭제한 뒤 설치 순서를 반복하세요. 설치 스크립트는 의도적으로 `public/` 문서 루트 밖에 두었으며 명령줄에서만 실행해야 합니다.
