# Employee Management System

PHP와 MySQL로 만든 사내 직원 관리 웹 애플리케이션입니다. 일반 직원은 휴가를 신청하고 처리 상태를 확인할 수 있으며, 관리자(Executive 부서)는 직원 정보와 휴가, 근태, 급여 관련 업무를 한곳에서 관리할 수 있습니다.

## 주요 기능

### 공통

- 사번과 비밀번호를 이용한 로그인
- 비밀번호 변경 및 로그아웃
- 연차, 병가, 개인 휴가 신청
- 본인의 휴가 신청 내역과 승인 상태 확인

### 관리자

- 직원 등록, 조회, 수정, 삭제
- 부서, 직급, 근무지, 입사일 기준 직원 검색
- 직원 상세 정보와 비상 연락처 확인
- 휴가 신청 승인 및 반려
- 월별 휴가·결근 현황과 부서별 통계 확인
- 연간, 분기, 월간 급여 내역 조회 및 CSV 저장
- 이번 달 생일자 확인
- 삭제한 직원의 정보, 처리자, 사유를 기록으로 보관

관리자 화면은 `department_id`가 `2`인 계정에만 열립니다. 그 외 계정은 일반 직원 화면으로 이동합니다.

## 사용 기술

- PHP
- MySQL / MySQLi
- HTML, CSS, JavaScript
- PHP Session 기반 로그인 상태 관리
- MySQL Trigger와 Stored Procedure

프레임워크 없이 PHP 페이지와 MySQL 쿼리로 구성한 프로젝트입니다.

## 프로젝트 구성

```text
.
├── login.php                    # 로그인
├── main.php                     # 일반 직원 메인 화면
├── admin_main.php               # 관리자 메인 화면
├── add_employee.php             # 직원 등록
├── list_employees.php           # 직원 목록 및 검색
├── employee_detail.php          # 직원 상세 정보
├── update_employee.php          # 직원 정보 수정
├── delete_employee.php          # 직원 삭제
├── delete_records.php           # 삭제 이력 조회
├── holiday_requests.php         # 휴가 신청
├── check_my_holiday.php         # 개인 휴가 내역 조회
├── check_holiday_requests.php   # 휴가 승인 및 반려
├── check_absence.php            # 월별 휴가·결근 리포트
├── salary_table.php             # 급여 리포트 및 CSV 저장
├── happybirthday.php            # 이달의 생일자 조회
├── change_password.php          # 비밀번호 변경
├── db_connection.php            # 애플리케이션 DB 연결 설정
├── setup/                       # DB 생성, 초기 데이터, 트리거 설정
└── images/                      # 로그인 배경 및 기본 프로필 이미지
```

`setup/extra_tables.php`에는 상품, 주문, 결제, 배송 등 확장용 테이블도 포함되어 있습니다. 현재 직원 관리 화면에서는 직접 사용하지 않습니다.

## 실행 방법

### 1. 실행 환경 준비

다음 환경이 필요합니다.

- PHP 8.x (`mysqli` 확장 활성화)
- MySQL 8.x
- PHP를 실행할 수 있는 로컬 웹 서버(XAMPP, WAMP 또는 PHP 내장 서버)

### 2. 데이터베이스 연결 설정

아래 두 파일의 접속 정보를 로컬 MySQL 환경에 맞게 수정합니다.

- `db_connection.php`
- `setup/db_connection.php`

기본 설정은 다음과 같습니다.

```php
$servername = "127.0.0.1";
$username = "root";
$password = "";
$dbname = "company_db";
$port = 3307;
```

MySQL 기본 포트를 사용한다면 `$port`를 `3306`으로 변경해야 합니다.

### 3. 데이터베이스 초기화

웹 서버를 실행한 뒤 `setup` 폴더의 파일을 아래 순서대로 한 번씩 엽니다. 예를 들어 PHP 내장 서버를 사용한다면 프로젝트 루트에서 다음 명령을 실행할 수 있습니다.

```bash
php -S localhost:8000
```

이후 브라우저에서 아래 주소를 순서대로 방문합니다.

1. `http://localhost:8000/setup/set_database.php`
2. `http://localhost:8000/setup/material_table.php`
3. `http://localhost:8000/setup/get_employees_data.php`
4. `http://localhost:8000/setup/department_table.php`
5. `http://localhost:8000/setup/position_table.php`
6. `http://localhost:8000/setup/office_table.php`
7. `http://localhost:8000/setup/employee_table.php`
8. `http://localhost:8000/setup/emergency_table.php`
9. `http://localhost:8000/setup/holiday_tables.php`
10. `http://localhost:8000/setup/set_holiday.php`
11. `http://localhost:8000/setup/delete_employee_logs.php`
12. `http://localhost:8000/setup/login_table.php`
13. `http://localhost:8000/setup/set_triger_and_procedure.php`
14. `http://localhost:8000/setup/extra_tables.php` (선택)

초기 직원 데이터는 `setup/Employees.csv`에서 불러옵니다. 트리거와 프로시저를 생성하는 계정에는 MySQL의 `TRIGGER`, `CREATE ROUTINE` 권한이 필요합니다.

### 4. 로그인

초기 데이터의 모든 직원 계정에는 다음 비밀번호가 설정됩니다.

```text
0000
```

`http://localhost:8000/login.php`에 접속한 뒤 CSV에 등록된 사번과 초기 비밀번호로 로그인합니다. 로그인 후에는 비밀번호 변경을 권장합니다.

## 데이터베이스 개요

| 테이블 | 용도 |
| --- | --- |
| `employees` | 직원 기본 정보와 부서, 직급, 근무지 연결 |
| `department` | 부서 정보 |
| `position` | 부서별 직급 정보 |
| `office` | 근무지 정보 |
| `emergency_table` | 직원 비상 연락처 |
| `login` | 직원별 비밀번호 해시 |
| `holiday_balance` | 휴가 유형별 잔여 일수 |
| `holiday_requests` | 휴가 신청과 처리 상태 |
| `delete_employee_logs` | 삭제된 직원과 삭제 사유 기록 |

새 직원을 등록하면 트리거가 로그인 계정과 기본 휴가 일수(연차 28일, 병가 10일, 개인 휴가 5일)를 함께 생성합니다. 직원 삭제는 저장 프로시저를 통해 처리되며, 삭제 전에 주요 정보와 사유를 별도 테이블에 남깁니다.

## 참고 사항

- 이 프로젝트는 로컬 개발 및 학습용으로 작성되었습니다.
- `setup/Employees.csv`의 직원 정보는 가상의 인물들입니다.
- DB 접속 정보와 초기 비밀번호는 운영 환경에서 그대로 사용하지 마세요.
- 라이선스는 별도로 명시되어 있지 않습니다.
