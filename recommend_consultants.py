import pandas as pd
import numpy as np
from sklearn.metrics.pairwise import cosine_similarity
import mysql.connector
import json
from datetime import datetime
import sys
import logging

# Set up logging
logging.basicConfig(filename='C:/xampp/htdocs/Companion/recommend_consultants.log', level=logging.DEBUG,
                    format='%(asctime)s - %(levelname)s - %(message)s')

def get_db_connection():
    try:
        conn = mysql.connector.connect(
            host="127.0.0.1",
            user="root",
            password="",
            database="companionx"
        )
        logging.info("Database connection successful")
        return conn
    except mysql.connector.Error as e:
        logging.error(f"Database connection failed: {e}")
        raise

def fetch_user_answers(user_id):
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT question_id, answer_text
            FROM user_signup_answers
            WHERE user_id = %s
        """, (user_id,))
        answers = cursor.fetchall()
        cursor.close()
        conn.close()
        logging.info(f"Fetched {len(answers)} answers for user_id {user_id}")
        return answers
    except Exception as e:
        logging.error(f"Error fetching user answers: {e}")
        raise

def fetch_consultants():
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT id, specialization, tags
            FROM consultants
            WHERE status = 'active' AND is_available = 1
        """)
        consultants = cursor.fetchall()
        cursor.close()
        conn.close()
        logging.info(f"Fetched {len(consultants)} active consultants")
        return consultants
    except Exception as e:
        logging.error(f"Error fetching consultants: {e}")
        raise

def create_user_vector(answers):
    vector = np.zeros(9)
    reasons = []

    for answer in answers:
        qid = answer['question_id']
        text = answer['answer_text']
        if qid in range(4, 13):
            vector[qid - 4] = int(text)
        elif qid == 999:
            reasons = text.split(', ')

    logging.info(f"User vector: {vector.tolist()}, Reasons: {reasons}")
    return vector, reasons

def create_consultant_vector(specialization, tags):
    specialization_map = {
        'Stress': [1, 0, 0, 0, 0, 0, 0, 0, 0],
        'Anxiety': [0, 1, 0, 0, 0, 0, 0, 0, 0],
        'Depression': [0, 0, 1, 0, 0, 0, 0, 0, 0],
        'PTSD': [0, 0, 0, 1, 0, 0, 0, 0, 0]
    }
    vector = specialization_map.get(specialization, [0] * 9)

    if tags:
        tags = tags.split(',')
        for tag in tags:
            if tag.strip().lower() in ['anxiety', 'youth']:
                vector[1] += 0.5

    logging.info(f"Consultant vector for {specialization}: {vector}")
    return np.array(vector)

def compute_recommendations(user_id):
    try:
        answers = fetch_user_answers(user_id)
        if not answers:
            logging.warning(f"No answers found for user_id {user_id}. Skipping recommendations.")
            return

        consultants = fetch_consultants()
        if not consultants:
            logging.warning("No active consultants found. Skipping recommendations.")
            return

        user_vector, user_reasons = create_user_vector(answers)

        recommendations = []
        for consultant in consultants:
            consultant_vector = create_consultant_vector(consultant['specialization'], consultant['tags'])
            score = cosine_similarity([user_vector], [consultant_vector])[0][0]

            for reason in user_reasons:
                if reason in consultant['specialization'] or (consultant['tags'] and reason.lower() in consultant['tags'].lower()):
                    score += 0.1

            recommendations.append({
                'consultant_id': consultant['id'],
                'score': min(score, 1.0)
            })

        recommendations = sorted(recommendations, key=lambda x: x['score'], reverse=True)[:5]
        logging.info(f"Top 5 recommendations for user_id {user_id}: {recommendations}")

        conn = get_db_connection()
        cursor = conn.cursor()
        cursor.execute("DELETE FROM ai_consultant_recommendations WHERE user_id = %s", (user_id,))
        for rec in recommendations:
            cursor.execute("""
                INSERT INTO ai_consultant_recommendations (user_id, consultant_id, recommendation_score, recommended_at)
                VALUES (%s, %s, %s, %s)
            """, (user_id, rec['consultant_id'], rec['score'], datetime.now()))
        conn.commit()
        cursor.close()
        conn.close()
        logging.info(f"Saved recommendations for user_id {user_id}")
    except Exception as e:
        logging.error(f"Error computing recommendations: {e}")
        # Do not raise error in production

if __name__ == "__main__":
    try:
        if len(sys.argv) >= 2:
            user_id = int(sys.argv[1])
            logging.info(f"Starting recommendation process via CLI for user_id {user_id}")
        else:
            # Local testing fallback user_id
            user_id = 1  # Change this to your test user ID
            logging.warning("No user_id passed via CLI. Using default user_id = 1 for testing.")

        compute_recommendations(user_id)

    except ValueError as e:
        logging.error(f"Invalid user_id format: {e}")
        sys.exit("Error: user_id must be an integer")

    except Exception as e:
        logging.error(f"Unexpected error: {e}")
        sys.exit("Error: An unexpected error occurred")
