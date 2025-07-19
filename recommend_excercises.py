import pandas as pd
import mysql.connector
import sys
import logging
import json
from datetime import datetime

# Set up logging
logging.basicConfig(
    filename='C:/xampp/htdocs/Companion/recommend_exercises.log',
    level=logging.DEBUG,
    format='%(asctime)s - %(levelname)s - %(message)s'
)

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

def fetch_user_signup_answers(user_id):
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT question_id, answer_text
            FROM user_signup_answers
            WHERE user_id = %s
            ORDER BY created_at DESC
        """, (user_id,))
        answers = cursor.fetchall()
        cursor.close()
        conn.close()
        logging.info(f"Fetched {len(answers)} signup answers for user_id {user_id}")
        return answers
    except Exception as e:
        logging.error(f"Error fetching user signup answers: {e}")
        return []

def fetch_user_moods(user_id):
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT mood, intensity, created_at
            FROM user_mood_entries
            WHERE user_id = %s
            ORDER BY created_at DESC
            LIMIT 3
        """, (user_id,))
        moods = cursor.fetchall()
        cursor.close()
        conn.close()
        logging.info(f"Fetched {len(moods)} moods for user_id {user_id}")
        return moods
    except Exception as e:
        logging.error(f"Error fetching user moods: {e}")
        return []

def fetch_exercises():
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT id, title, description, category FROM mental_exercises")
        exercises = cursor.fetchall()
        cursor.close()
        conn.close()
        logging.info(f"Fetched {len(exercises)} mental exercises")
        return exercises
    except Exception as e:
        logging.error(f"Error fetching exercises: {e}")
        return []

def store_recommendations(user_id, recommendations):
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        for rec in recommendations:
            cursor.execute("""
                INSERT INTO user_exercise_recommendations (user_id, exercise_id, recommended_at)
                VALUES (%s, %s, %s)
                ON DUPLICATE KEY UPDATE recommended_at = %s
            """, (user_id, rec['id'], datetime.now(), datetime.now()))
        conn.commit()
        cursor.close()
        conn.close()
        logging.info(f"Stored {len(recommendations)} recommendations for user_id {user_id}")
    except Exception as e:
        logging.error(f"Error storing recommendations: {e}")

def recommend_exercises(user_id):
    try:
        answers = fetch_user_signup_answers(user_id)
        moods = fetch_user_moods(user_id)
        exercises = fetch_exercises()

        if not answers or not exercises:
            logging.warning(f"No answers or exercises for user_id {user_id}")
            return []

        # Define weights for scoring
        weights = {
            'mood': 0.4,
            'intensity': 0.3,
            'needs': 0.3
        }

        # Map signup answers to needs
        needs_map = {
            'Reduce stress': 'Stress',
            'Manage anxiety': 'Anxiety',
            'Deal with depression': 'Depression',
            'Boost confidence': 'Mood',
            'Overcome burnout': 'Stress',
            'Improve sleep': 'Stress',
            'Enhance emotional wellbeing': 'Mood',
            'Find a counselor': 'CBT'
        }

        # Extract user needs from question_id 999
        user_needs = []
        question_scores = {}
        for answer in answers:
            if answer['question_id'] == 999:
                needs = answer['answer_text'].split(", ")
                for need in needs:
                    need = need.strip()
                    if need in needs_map:
                        user_needs.append(needs_map[need])
            elif answer['question_id'] in range(4, 13):
                try:
                    question_scores[answer['question_id']] = int(answer['answer_text'])
                except ValueError:
                    question_scores[answer['question_id']] = 5  # Default score

        # Calculate average intensity from question scores (4-12)
        avg_score = sum(question_scores.values()) / len(question_scores) if question_scores else 5

        # Get dominant mood and intensity
        dominant_mood = 'Neutral'
        avg_mood_intensity = 5
        if moods:
            mood_counts = pd.Series([m['mood'] for m in moods]).mode()
            dominant_mood = mood_counts[0] if not mood_counts.empty else 'Neutral'
            avg_mood_intensity = sum(m['intensity'] for m in moods) / len(moods)

        # Combine intensities (signup answers and mood entries)
        combined_intensity = (avg_score + avg_mood_intensity) / 2

        # Score exercises
        recommendations = []
        for exercise in exercises:
            score = 0
            category = exercise['category']

            # Mood compatibility
            if dominant_mood in ['Sad', 'Angry'] and category == 'CBT':
                score += weights['mood'] * 0.8
            elif dominant_mood == 'Neutral' and category == 'Mindfulness':
                score += weights['mood'] * 0.7
            elif dominant_mood in ['Happy', 'Excited'] and category == 'Gratitude':
                score += weights['mood'] * 0.9

            # Intensity adjustment
            if combined_intensity >= 7 and category in ['Stress', 'Anxiety']:
                score += weights['intensity'] * 0.7
            elif combined_intensity <= 3 and category in ['Depression', 'Mood']:
                score += weights['intensity'] * 0.8

            # Needs matching
            for need in user_needs:
                if need == category:
                    score += weights['needs'] * (1 / len(user_needs) if user_needs else 1)

            recommendations.append({
                'id': exercise['id'],
                'title': exercise['title'],
                'description': exercise['description'],
                'category': exercise['category'],
                'score': min(score, 1.0),
                'is_daily_task': False
            })

        # Sort by score and select top 3
        recommendations.sort(key=lambda x: x['score'], reverse=True)
        top_recommendations = recommendations[:3]

        # Mark one exercise per category as daily task
        selected_categories = set()
        for rec in top_recommendations:
            if rec['category'] not in selected_categories:
                rec['is_daily_task'] = True
                selected_categories.add(rec['category'])

        # Store recommendations in database
        store_recommendations(user_id, top_recommendations)

        logging.info(f"Recommended {len(top_recommendations)} exercises for user_id {user_id}")
        return top_recommendations
    except Exception as e:
        logging.error(f"Error recommending exercises: {e}")
        return []

if __name__ == "__main__":
    try:
        if len(sys.argv) < 2:
            logging.error("No user_id provided")
            sys.exit("Error: Please provide a user_id as a command-line argument")
        user_id = int(sys.argv[1])
        logging.info(f"Starting exercise recommendation for user_id {user_id}")
        recommendations = recommend_exercises(user_id)
        print(json.dumps(recommendations, indent=2))
    except ValueError:
        logging.error("Invalid user_id format")
        sys.exit("Error: user_id must be an integer")
    except Exception as e:
        logging.error(f"Unexpected error: {e}")
        sys.exit("Error: An unexpected error occurred")
