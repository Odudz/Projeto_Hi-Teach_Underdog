# Projeto_Hi-Teach_Underdog
Projeto integrador da PUCPR envolvendo Desenvolvimento Web, Banco de Dados e Engenharia de Requisitos.

Link do site: https://hi-teach.infinityfreeapp.com/index.php

Status: Site disponível até =~ 10/2027

Este projeto usa para meios de publicação e desenvolvimento as seguintes ferramentas:

Infinity Free; #Utilizado para Hosting e para o Domain

meet.jit.si: #Utilizado para as transmissões ao vivo


Matérias e cursos:

As matérias existentes organizam os cursos. Professores podem criar cursos, cadastrar aulas com links do YouTube e publicar materiais e atividades na sala virtual. Alunos podem se matricular e acompanhar as aulas.

Banco de dados: para uma instalação nova, utilize config/hi_teach.sql. Para acrescentar os recursos a um banco MariaDB já existente, faça um backup e execute config/migracao_materias.sql no banco hi_teach. O arquivo cria as tabelas e adiciona as colunas necessárias sem substituir os registros existentes.
