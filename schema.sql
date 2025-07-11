create table patients (
	id int auto_increment primary key,
	first_name varchar(64) not null,
	last_name varchar(64) not null,
	date_of_birth date not null,
	gender ENUM('male', 'female') not null,
	address varchar(255) not null
);

create table payment_methods (
	id int auto_increment primary key,
	patient_id int not null,
	payment_data JSON not null,
	payment_type varchar(16) not null,
	constraint fk_patient_id foreign key (patient_id) references patients (id)
);
