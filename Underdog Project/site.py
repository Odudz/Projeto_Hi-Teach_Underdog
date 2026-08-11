from flask import Flask, render_template
import mysql.connector

app = Flask(__name__)

@app.route("/")
def home():
    # 1. Abre bd do MySQL (Lembre de ligar o MySQL e o Apache no painel do XAMPP!)
    conexao = mysql.connector.connect(
        host="localhost", 
        user="root", 
        password="", 
        database="site_underdog"
    )
    cursor = conexao.cursor()
    

if __name__ == "__main__":
    app.run(debug=True)
