<p align="right">
  <a href="README.md">English</a> · <strong>한국어</strong>
</p>

# 데이터베이스 설치

[← 프로젝트 소개로 돌아가기](../README.ko.md)

설치 스크립트는 가상의 CSV 데이터를 정규화된 애플리케이션 스키마로 변환합니다. 비어 있는 로컬 데이터베이스를 대상으로 아래 순서대로 한 번씩 실행하세요.

## 연결 설정

연결 정보는 `config/database.php`에서 관리하며 `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` 환경 변수로 덮어쓸 수 있습니다. 기본값은 MySQL 포트 `3307`과 데이터베이스 이름 `company_db`입니다.

리포지토리 루트에서 애플리케이션을 실행합니다.

```bash
php -S 127.0.0.1:8000
```

## 초기화 순서

다음 주소를 나열된 순서대로 방문합니다.

1. [`/database/setup/set_database.php`](http://127.0.0.1:8000/database/setup/set_database.php)
2. [`/database/setup/material_table.php`](http://127.0.0.1:8000/database/setup/material_table.php)
3. [`/database/setup/get_employees_data.php`](http://127.0.0.1:8000/database/setup/get_employees_data.php)
4. [`/database/setup/department_table.php`](http://127.0.0.1:8000/database/setup/department_table.php)
5. [`/database/setup/position_table.php`](http://127.0.0.1:8000/database/setup/position_table.php)
6. [`/database/setup/office_table.php`](http://127.0.0.1:8000/database/setup/office_table.php)
7. [`/database/setup/employee_table.php`](http://127.0.0.1:8000/database/setup/employee_table.php)
8. [`/database/setup/emergency_table.php`](http://127.0.0.1:8000/database/setup/emergency_table.php)
9. [`/database/setup/holiday_tables.php`](http://127.0.0.1:8000/database/setup/holiday_tables.php)
10. [`/database/setup/set_holiday.php`](http://127.0.0.1:8000/database/setup/set_holiday.php)
11. [`/database/setup/delete_employee_logs.php`](http://127.0.0.1:8000/database/setup/delete_employee_logs.php)
12. [`/database/setup/login_table.php`](http://127.0.0.1:8000/database/setup/login_table.php)
13. [`/database/setup/set_triggers_and_procedure.php`](http://127.0.0.1:8000/database/setup/set_triggers_and_procedure.php)
14. 선택 사항: [`/database/setup/extra_tables.php`](http://127.0.0.1:8000/database/setup/extra_tables.php)

마지막 필수 단계에는 MySQL의 `TRIGGER`와 `CREATE ROUTINE` 권한이 필요합니다.

## 초기 데이터 동작

- 원본 파일: `seeds/employees.csv`
- 모든 인물과 연락처 정보는 가상 데이터입니다.
- 초기 로그인 비밀번호 `0000`은 bcrypt 해시로 저장됩니다.
- 초기 휴가 잔여 일수는 연차 28일, 병가 10일, 개인 휴가 5일입니다.

## 선택형 확장 스키마

`extra_tables.php`는 학습 목적으로 커머스, 결제, 재고, 고객, 배송 테이블을 생성합니다. 현재 인사 운영 UI에서는 이 테이블들을 사용하지 않습니다.

## 로컬 데이터베이스 초기화

설치 과정은 새 데이터베이스를 기준으로 작성되었습니다. 다시 초기화하려면 사용하는 MySQL 관리 도구에서 로컬 `company_db` 데이터베이스를 삭제한 뒤 설치 순서를 반복하세요. 배포 환경에서는 `database/setup/` 경로를 외부에 공개하지 마세요.
